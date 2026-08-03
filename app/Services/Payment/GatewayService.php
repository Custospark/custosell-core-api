<?php

namespace App\Services\Payment;

use App\Enums\Billing\ReferralStatus;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\Subscription;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\SubscriptionScheduledChangeRepositoryInterface;
use App\Services\Contracts\PaymentServiceInterface;
use App\Services\Contracts\SubscriptionServiceInterface;
use App\Services\CreditService;
use App\Services\Currency\Contracts\CurrencyExchangeServiceInterface;
use App\Services\Contracts\ReferralServiceInterface;
use App\Services\Payment\Gateways\Exceptions\GatewayException;
use App\Services\Payment\Validation\PaymentValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
class GatewayService
{
    use \App\Services\Payment\Concerns\HandlesPaymentApproval;
    use \App\Services\Payment\Concerns\InitiatesGatewayPayments;
    public function __construct(
        private readonly GatewayManager $gatewayManager,
        private readonly PaymentRepositoryInterface $paymentRepo,
        private readonly PaymentServiceInterface $paymentService,
        private readonly SubscriptionServiceInterface $subscriptionService,
        private readonly SubscriptionScheduledChangeRepositoryInterface $scheduledChangeRepo,
        private readonly CreditService $creditService,
        private readonly CurrencyExchangeServiceInterface $currencyExchange,
        private readonly ReferralServiceInterface $referralService,
        private readonly PaymentValidator $paymentValidator,
    ) {}

    public function initiatePayment(Subscription $subscription, string $gatewayName, array $data): array
    {
        $driver = $this->gatewayManager->driver($gatewayName);

        if (!$driver->isEnabled()) {
            throw new GatewayException(
                "Gateway '{$gatewayName}' is not currently enabled.",
                $gatewayName
            );
        }

        // 1. Resolve payment currency authoritatively from business
        $business = $subscription->business;
        $businessCurrency = strtoupper($business?->currency ?? 'UGX');
        $paymentCurrency = in_array($businessCurrency, $driver->getSupportedCurrencies())
            ? $businessCurrency
            : 'USD';

        // 2. For non-USD currencies, resolve exchange rate NOW — fail hard if unavailable
        $exchangeRate = null;
        if ($paymentCurrency !== 'USD') {
            $exchangeRate = $this->currencyExchange->getExchangeRate('USD', $paymentCurrency);
            if ($exchangeRate === null) {
                throw new GatewayException(
                    "Cannot initiate payment: exchange rate unavailable for {$paymentCurrency}. Please try again later.",
                    $gatewayName
                );
            }
        }

        // 3. Compute authoritative amount for known payment types (ignore frontend value)
        // Always uses live plan prices so promotions (Black Friday, etc.) apply to everyone.
        // The billing cycle is taken from the request when the user picked one (e.g. resubscribing
        // yearly), but renewals always follow the subscription's stored cycle — a user on yearly
        // must not be switched to monthly (or vice versa) through a renewal payment.
        $paymentType = $data['payment_type'] ?? 'subscription';
        $effectiveCycle = $paymentType === 'renewal'
            ? ($subscription->billing_cycle ?? 'monthly')
            : (in_array($data['billing_cycle'] ?? null, ['monthly', 'yearly'], true)
                ? $data['billing_cycle']
                : ($subscription->billing_cycle ?? 'monthly'));
        $data['billing_cycle'] = $effectiveCycle;
        $plan = $this->resolveEffectivePlan($subscription, $data);
        $data['amount'] = match ($paymentType) {
            'onboarding' => (float) ($plan?->onboarding_fee_usd ?? 0),
            'subscription', 'renewal' => $effectiveCycle === 'yearly'
                ? (float) ($plan?->price_yearly_usd ?? 0)
                : (float) ($plan?->price_monthly_usd ?? 0),
            default => (float) ($data['amount'] ?? 0),
        };

        // Validate authoritative amount in USD before applying any discounts
        $data['currency'] = 'USD';
        $this->paymentValidator->validatePaymentAmount($subscription, $data);

        // Apply pending referral discount directly (no pre-existing credit needed)
        $referralDiscount = 0;
        $referral = Referral::where('subscription_id', $subscription->id)
            ->where('status', ReferralStatus::PENDING)
            ->first();
        if ($referral && (float) $referral->discount_applied > 0) {
            $referralDiscount = min((float) $referral->discount_applied, (float) $data['amount']);
            $data['amount'] = round((float) $data['amount'] - $referralDiscount, 2);
        }

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
        // Apply billing credits to reduce the amount (still in USD) before creating the payment
        $creditApplications = [];
        $creditResult = ['credit_used' => 0];
        $originalAmount = (float) $data['amount'];
        if ($originalAmount > 0) {
            $creditResult = $this->creditService->applyToRenewal($subscription, $originalAmount);
            if ($creditResult['credit_used'] > 0) {
                $data['amount'] = $creditResult['remaining'];
                $creditApplications = $creditResult['applications'];
            }
        }

        // If credits fully cover the payment, bypass the gateway entirely
        if ($originalAmount > 0 && $data['amount'] <= 0) {
            try {
                return DB::transaction(function () use ($subscription, $gatewayName, $data, $originalAmount, $creditApplications, $idempotencyKey, $referralDiscount) {
                $ourRef = 'CREDIT-' . now()->format('YmdHis') . '-' . $subscription->id;
                $payment = $this->paymentService->createPending([
                    'subscription_id' => $subscription->id,
                    'business_id' => $subscription->business_id,
                    'amount' => 0,
                    'currency' => 'USD',
                    'method' => 'credit',
                    'payment_type' => $data['payment_type'] ?? 'subscription',
                    'gateway_name' => $gatewayName,
                    'paid_at' => now(),
                    'transaction_reference' => $ourRef,
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => array_merge(
                        $data['metadata'] ?? [],
                        [
                            'credit_full_payment' => true,
                            'original_amount' => $originalAmount,
                            'referral_discount_applied' => $referralDiscount,
                            'credit_application_ids' => array_map(fn ($a) => $a->id, array_filter($creditApplications)),
                        ]
                    ),
                ]);

                $this->paymentRepo->update($payment, [
                    'status' => 'completed',
                    'approved_at' => now(),
                    'gateway_response' => ['type' => 'credit', 'message' => 'Paid entirely by credit.'],
                ]);

                $payment->refresh();

                foreach ($creditApplications as $app) {
                    $app->update(['billing_payment_id' => $payment->id]);
                }

                $this->handlePaymentType($payment);

                Log::info('[GatewayService] Payment completed via credit (no gateway)', [
                    'subscription_id' => $subscription->id,
                    'payment_id' => $payment->id,
                    'payment_type' => $data['payment_type'] ?? 'subscription',
                ]);

                return [
                    'success' => true,
                    'payment_id' => $payment->id,
                    'gateway' => 'credit',
                    'type' => 'credit',
                    'redirect_url' => null,
                    'reference' => $ourRef,
                    'message' => 'Payment completed entirely by credit.',
                ];
                });
            } catch (\Throwable $e) {
                if (!empty($creditApplications)) {
                    $this->creditService->reverseApplications($creditApplications);
                }
                throw $e;
            }
        }

        // Convert amount to payment currency after credit application
        $data['currency'] = $paymentCurrency;
        if ($paymentCurrency !== 'USD' && $exchangeRate !== null) {
            $data['amount'] = round((float) $data['amount'] * $exchangeRate, 2);
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
            'metadata' => array_merge(
                $data['metadata'] ?? [],
                [
                    'billing_cycle' => $data['billing_cycle'] ?? $subscription->billing_cycle ?? 'monthly',
                    'original_amount' => $originalAmount,
                    'referral_discount_applied' => $referralDiscount,
                    'credit_used' => $creditResult['credit_used'] ?? 0,
                    'credit_application_ids' => array_map(fn ($a) => $a->id, $creditApplications),
                ]
            ),
            'idempotency_key' => $idempotencyKey,
        ]);

        $ourRef = "CUSTOSELL-{$payment->id}-" . now()->format('YmdHis');
        $business = $subscription->business;
        $countryCode = $business?->country ? mb_substr($business->country, 0, 2) : 'UG';

        return $this->initiateWithDriver(
            $driver,
            $data,
            $payment,
            $subscription,
            $plan,
            $gatewayName,
            $ourRef,
            $countryCode,
            $originalAmount,
            $creditApplications,
        );
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

        $payment = $this->paymentValidator->resolvePaymentFromWebhook($webhookData);

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

        $payment = $this->paymentValidator->resolvePaymentFromWebhook($callbackData);

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

    public function processZeroCostUpgrade(Subscription $subscription, int $toPlanId, ?string $billingCycle = null): void
    {
        DB::transaction(function () use ($subscription, $toPlanId, $billingCycle) {
            $payment = $this->paymentService->createPending([
                'subscription_id' => $subscription->id,
                'business_id' => $subscription->business_id,
                'amount' => 0,
                'currency' => 'USD',
                'method' => 'internal',
                'payment_type' => 'upgrade_proration',
                'gateway_name' => 'internal',
                'paid_at' => now(),
                'status' => 'completed',
                'approved_at' => now(),
                'metadata' => [
                    'to_plan_id' => $toPlanId,
                    'billing_cycle' => $billingCycle,
                    'zero_cost_upgrade' => true,
                ],
            ]);

            $payment->refresh();

            $this->paymentService->complete($payment);
            $payment->refresh();

            $this->handlePaymentType($payment);
        });
    }

    private function resolveEffectivePlan(Subscription $subscription, array $data): ?Plan
    {
        $plan = $subscription->plan;

        if (($data['payment_type'] ?? null) === 'onboarding') {
            $metaPlanId = $data['metadata']['plan_id'] ?? null;
            if ($metaPlanId && (int) $metaPlanId !== $subscription->plan_id) {
                $target = Plan::find((int) $metaPlanId);
                if ($target) {
                    return $target;
                }
            }
        }

        return $plan;
    }
}
