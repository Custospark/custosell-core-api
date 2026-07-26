<?php

namespace App\Services\Payment;

use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\SubscriptionScheduledChangeRepositoryInterface;
use App\Services\Contracts\PaymentServiceInterface;
use App\Services\Contracts\SubscriptionServiceInterface;
use App\Services\Payment\Gateways\Exceptions\GatewayException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GatewayService
{
    protected \App\Services\CreditService $creditService;

    public function __construct(
        private readonly GatewayManager $gatewayManager,
        private readonly PaymentRepositoryInterface $paymentRepo,
        private readonly PaymentServiceInterface $paymentService,
        private readonly SubscriptionServiceInterface $subscriptionService,
        private readonly SubscriptionScheduledChangeRepositoryInterface $scheduledChangeRepo,
    ) {
        $this->creditService = app(\App\Services\CreditService::class);
    }

    public function initiatePayment(Subscription $subscription, string $gatewayName, array $data): array
    {
        $driver = $this->gatewayManager->driver($gatewayName);

        if (!$driver->isEnabled()) {
            throw new GatewayException(
                "Gateway '{$gatewayName}' is not currently enabled.",
                $gatewayName
            );
        }

        // Auto-apply available credit on renewal payments
        $originalAmount = (float) ($data['amount'] ?? 0);
        $creditUsed = 0;
        if (($data['payment_type'] ?? '') === 'renewal') {
            $result = $this->creditService->applyToRenewal($subscription, $originalAmount);
            $creditUsed = $result['credit_used'];
            if ($creditUsed > 0) {
                $data['amount'] = $result['remaining'];
                $data['metadata']['credit_applied'] = $creditUsed;
                $data['metadata']['credit_application_ids'] = array_map(
                    fn ($a) => $a->id, $result['applications']
                );
                $data['metadata']['original_amount'] = $originalAmount;
            }
        }

        // If credit covers the full amount, skip gateway entirely
        if (($data['payment_type'] ?? '') === 'renewal' && $data['amount'] <= 0) {
            return $this->completeWithCredit($subscription, $gatewayName, $data);
        }

        // Validate amount against expected subscription prices
        $this->validatePaymentAmount($subscription, $data);

        // H5: Idempotency check — return existing payment if same key used
        $idempotencyKey = $data['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $existing = $this->paymentRepo->findByIdempotencyKey($idempotencyKey);
            if ($existing) {
                Log::info('[GatewayService] Duplicate payment prevented by idempotency key', [
                    'idempotency_key' => $idempotencyKey,
                    'existing_payment_id' => $existing->id,
                ]);
                return [
                    'success' => true,
                    'payment_id' => $existing->id,
                    'gateway' => $gatewayName,
                    'type' => 'existing',
                    'redirect_url' => null,
                    'reference' => $existing->transaction_reference,
                    'message' => 'Payment already initiated.',
                ];
            }
        }

        $payment = $this->paymentService->createPending([
            'subscription_id' => $subscription->id,
            'business_id' => $subscription->business_id,
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency']),
            'method' => 'gateway',
            'payment_type' => $data['payment_type'] ?? 'subscription',
            'gateway_name' => $gatewayName,
            'paid_at' => null,
            'metadata' => $data['metadata'] ?? null,
            'idempotency_key' => $idempotencyKey,
        ]);

        $plan = $subscription->plan;
        $ourRef = "CUSTOSELL-{$payment->id}-" . now()->format('YmdHis');
        $business = $subscription->business;
        $countryCode = $business?->country ? mb_substr($business->country, 0, 2) : 'UG';

        $driverPayload = [
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency']),
            'our_reference' => $ourRef,
            'phone_number' => $data['phone_number'] ?? null,
            'email' => $data['email'] ?? null,
            'customer_name' => $data['customer_name'] ?? null,
            'description' => 'Custosell subscription — ' . ($plan?->name ?? 'Plan'),
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'country_code' => $countryCode,
        ];

        try {
            $result = $driver->initiate($driverPayload);

            $this->paymentRepo->update($payment, [
                'gateway_transaction_id' => $result['gateway_txn_id'] ?? $result['gateway_ref'],
                'transaction_reference' => $ourRef,
                'gateway_response' => [
                    'initiation' => $result['raw_response'] ?? [],
                    'our_reference' => $ourRef,
                ],
            ]);

            if ($result['type'] === 'bypass') {
                DB::transaction(function () use ($payment, $result) {
                    $this->paymentRepo->update($payment, [
                        'status' => 'completed',
                        'paid_at' => now(),
                        'approved_at' => now(),
                        'gateway_transaction_id' => $result['gateway_txn_id'],
                        'transaction_reference' => $result['gateway_ref'],
                        'gateway_response' => ['initiation' => $result['raw_response'] ?? []],
                    ]);
                    $payment->refresh();
                    $this->handlePaymentType($payment);
                });
                Log::info('[GatewayService] Payment auto-approved (bypass)', [
                    'payment_id' => $payment->id,
                ]);
                return [
                    'success' => true,
                    'payment_id' => $payment->id,
                    'gateway' => $gatewayName,
                    'type' => 'bypass',
                    'redirect_url' => null,
                    'reference' => $result['gateway_ref'],
                    'message' => 'Payment bypassed (development mode).',
                ];
            }

            Log::info('[GatewayService] Payment initiated', [
                'payment_id' => $payment->id,
                'gateway' => $gatewayName,
                'type' => $result['type'],
                'gateway_txn_id' => $result['gateway_txn_id'],
            ]);

            return [
                'success' => true,
                'payment_id' => $payment->id,
                'gateway' => $gatewayName,
                'type' => $result['type'],
                'redirect_url' => $result['redirect_url'] ?? null,
                'reference' => $result['gateway_ref'],
                'message' => $result['message'],
            ];

        } catch (\Throwable $e) {
            $this->paymentRepo->update($payment, [
                'status' => 'failed',
                'rejection_reason' => "Gateway initiation failed: {$e->getMessage()}",
            ]);

            Log::error('[GatewayService] Initiation failed', [
                'payment_id' => $payment->id,
                'gateway' => $gatewayName,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function processWebhook(string $gatewayName, Request $request): void
    {
        $driver = $this->gatewayManager->driver($gatewayName);

        if (!$driver->verifyWebhookSignature($request)) {
            Log::warning("[GatewayService] Invalid webhook signature for {$gatewayName}");
            return;
        }

        $webhookData = $driver->parseWebhookPayload($request);

        Log::info("[GatewayService] Webhook received from {$gatewayName}", [
            'gateway_txn_id' => $webhookData['gateway_txn_id'],
            'status' => $webhookData['status'],
        ]);

        if (empty($webhookData['gateway_txn_id']) && empty($webhookData['our_reference'])) {
            Log::warning("[GatewayService] Webhook missing identifiers", $webhookData);
            return;
        }

        $payment = $this->resolvePaymentFromWebhook($webhookData);

        if (!$payment) {
            Log::error("[GatewayService] Payment not found for webhook", $webhookData);
            return;
        }

        if (!$payment->isPending()) {
            Log::info("[GatewayService] Payment #{$payment->id} already processed — skipping.");
            return;
        }

        $verification = $driver->verify($webhookData['gateway_txn_id']);

        if (!$verification['success'] || $verification['status'] !== 'successful') {
            Log::info("[GatewayService] Payment not yet successful", [
                'payment_id' => $payment->id,
                'status' => $verification['status'],
            ]);

            $this->paymentRepo->update($payment, [
                'gateway_response' => array_merge(
                    $payment->gateway_response ?? [],
                    ['webhook' => $webhookData, 'verification' => $verification]
                ),
            ]);
            return;
        }

        $this->autoApprove($payment, $webhookData, $verification);
    }

    public function processCallback(string $gatewayName, Request $request): array
    {
        $driver = $this->gatewayManager->driver($gatewayName);
        $callbackData = $driver->parseWebhookPayload($request);

        if (empty($callbackData['gateway_txn_id'])) {
            return ['success' => false, 'message' => 'Missing transaction identifier in callback.', 'payment_id' => null];
        }

        $verification = $driver->verify($callbackData['gateway_txn_id']);

        if (!$verification['success'] || $verification['status'] !== 'successful') {
            return [
                'success' => false,
                'message' => 'Payment could not be verified: ' . ($verification['message'] ?? 'status ' . $verification['status']),
                'payment_id' => null,
            ];
        }

        $payment = $this->resolvePaymentFromWebhook($callbackData);

        if (!$payment) {
            Log::error("[GatewayService] Callback — payment not found", $callbackData);
            return ['success' => false, 'message' => 'Payment record not found.', 'payment_id' => null];
        }

        if (!$payment->isPending()) {
            return ['success' => true, 'message' => 'Payment already confirmed.', 'payment_id' => $payment->id];
        }

        $this->autoApprove($payment, $callbackData, $verification);

        return [
            'success' => true,
            'message' => 'Payment confirmed. Subscription activated.',
            'payment_id' => $payment->id,
        ];
    }

    private function completeWithCredit(Subscription $subscription, string $gatewayName, array $data): array
    {
        return $this->creditService->completeRenewalWithCredit(
            subscription: $subscription,
            gatewayName: $gatewayName,
            data: $data,
            paymentService: $this->paymentService,
            paymentRepo: $this->paymentRepo,
            onPaymentCompleted: function ($payment) {
                $this->handlePaymentType($payment);
            }
        );
    }

    private function handlePaymentType(BillingPayment $payment): void
    {
        $paymentType = $payment->payment_type instanceof \App\Enums\Billing\PaymentType
            ? $payment->payment_type->value
            : $payment->payment_type;

        $subscription = $payment->subscription;

        match ($paymentType) {
            'onboarding' => $this->subscriptionService->activateAfterOnboarding($subscription),
            'subscription' => $this->subscriptionService->activateSubscription($subscription, $payment, null),
            'renewal' => $this->subscriptionService->renewSubscription($subscription, $payment),
            'upgrade_proration' => $this->handleUpgradeProration($payment, $subscription),
            default => null,
        };
    }

    private function handleUpgradeProration(BillingPayment $payment, $subscription): void
    {
        $metadata = $payment->metadata ?? [];
        $toPlanId = $metadata['to_plan_id'] ?? null;

        if (!$toPlanId) {
            Log::warning('[GatewayService] Upgrade payment missing to_plan_id metadata', [
                'payment_id' => $payment->id,
            ]);
            return;
        }

        if ((int) $toPlanId === $subscription->plan_id) {
            Log::info('[GatewayService] Upgrade skipped — already on target plan', [
                'payment_id' => $payment->id,
                'plan_id' => $subscription->plan_id,
            ]);
            return;
        }

        DB::transaction(function () use ($payment, $subscription, $toPlanId) {
            $this->scheduledChangeRepo->create([
                'subscription_id' => $subscription->id,
                'business_id' => $subscription->business_id,
                'change_type' => 'upgrade',
                'from_plan_id' => $subscription->plan_id,
                'to_plan_id' => $toPlanId,
                'effective_at' => now(),
                'status' => 'applied',
                'proration_amount' => $payment->amount,
                'metadata' => [
                    'source' => 'payment_webhook',
                    'payment_id' => $payment->id,
                ],
            ]);

            $this->subscriptionService->changePlan($subscription, (int) $toPlanId);
        });

        Log::info('[GatewayService] Upgrade completed via payment', [
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'to_plan_id' => $toPlanId,
        ]);
    }

    private function autoApprove(BillingPayment $payment, array $webhookData, array $verification): void
    {
        DB::transaction(function () use ($payment, $webhookData, $verification) {
            $this->paymentRepo->update($payment, [
                'status' => 'completed',
                'approved_at' => now(),
                'paid_at' => $payment->paid_at ?? now(),
                'gateway_transaction_id' => $verification['gateway_txn_id'] ?? $payment->gateway_transaction_id,
                'gateway_response' => array_merge(
                    $payment->gateway_response ?? [],
                    ['webhook' => $webhookData, 'verification' => $verification]
                ),
            ]);

            $payment->refresh();

            $this->handlePaymentType($payment);

            Log::info('[GatewayService] Payment auto-approved', [
                'payment_id' => $payment->id,
                'gateway' => $payment->gateway_name,
                'subscription_id' => $payment->subscription_id,
                'business_id' => $payment->business_id,
            ]);
        });
    }

    public function confirmPayment(int $paymentId): array
    {
        $payment = $this->paymentRepo->find($paymentId);

        if (!$payment) {
            return ['success' => false, 'message' => 'Payment not found.', 'payment_id' => null];
        }

        if (!$payment->isPending()) {
            $status = $payment->status instanceof \App\Enums\Billing\PaymentStatus
                ? $payment->status->value
                : $payment->status;
            return ['success' => true, 'message' => "Payment already {$status}.", 'payment_id' => $payment->id];
        }

        $driver = $this->gatewayManager->driver($payment->gateway_name);
        $verification = $driver->verify($payment->gateway_transaction_id);

        if (!$verification['success'] || $verification['status'] !== 'successful') {
            return [
                'success' => false,
                'message' => 'Payment not yet confirmed: ' . ($verification['message'] ?? 'status ' . $verification['status']),
                'payment_id' => $payment->id,
            ];
        }

        $this->autoApprove($payment, ['gateway_txn_id' => $payment->gateway_transaction_id], $verification);

        return [
            'success' => true,
            'message' => 'Payment confirmed. Subscription activated.',
            'payment_id' => $payment->id,
        ];
    }

    private function validatePaymentAmount(Subscription $subscription, array $data): void
    {
        $paymentType = $data['payment_type'] ?? 'subscription';
        $amount = (float) ($data['amount'] ?? 0);
        // Include credit-applied amount in validation so reduced amounts still pass
        if (!empty($data['metadata']['credit_applied'])) {
            $amount += (float) $data['metadata']['credit_applied'];
        }
        $currency = strtoupper($data['currency'] ?? 'USD');
        $tolerance = 0.50;

        $expectedUsd = match ($paymentType) {
            'onboarding' => (float) ($subscription->onboarding_fee_usd ?? 0),
            'subscription' => $subscription->billing_cycle === 'yearly'
                ? (float) ($subscription->price_yearly_usd ?? 0)
                : (float) ($subscription->price_monthly_usd ?? 0),
            'renewal' => $subscription->billing_cycle === 'yearly'
                ? (float) ($subscription->price_yearly_usd ?? 0)
                : (float) ($subscription->price_monthly_usd ?? 0),
            'upgrade_proration' => $amount,
            default => $amount,
        };

        if ($paymentType === 'upgrade_proration') {
            return;
        }

        if ($currency === 'USD') {
            $expected = $expectedUsd;
        } elseif ($currency === 'UGX') {
            $expected = match ($paymentType) {
                'onboarding' => (float) ($subscription->onboarding_fee_ugx ?? 0),
                default => $subscription->billing_cycle === 'yearly'
                    ? (float) ($subscription->price_yearly ?? 0)
                    : (float) ($subscription->price_monthly ?? 0),
            };
            $tolerance = max(50, $expected * 0.01);
        } else {
            $rate = app(\App\Services\Currency\Contracts\CurrencyExchangeServiceInterface::class)
                ->getExchangeRate('USD', $currency);
            $expected = $rate ? round($expectedUsd * $rate, 2) : $expectedUsd;
            $tolerance = max(50, $expected * 0.02);
        }

        if ($expected > 0 && abs($amount - $expected) > $tolerance) {
            throw new GatewayException(
                "Payment amount {$currency} {$amount} does not match expected amount {$currency} {$expected} for {$paymentType}.",
                $data['gateway_name'] ?? 'unknown',
                ['payment_type' => $paymentType, 'expected' => $expected, 'received' => $amount, 'currency' => $currency]
            );
        }
    }

    private function resolvePaymentFromWebhook(array $webhookData): ?BillingPayment
    {
        if (!empty($webhookData['gateway_txn_id'])) {
            $payment = $this->paymentRepo->findByGatewayTransactionId($webhookData['gateway_txn_id']);
            if ($payment) return $payment;
        }

        if (!empty($webhookData['our_reference'])) {
            return $this->paymentRepo->findByTransactionReference($webhookData['our_reference']);
        }

        return null;
    }
}
