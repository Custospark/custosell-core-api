<?php

namespace App\Services\Payment;

use App\Enums\Billing\ReferralStatus;
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
        // Always uses live plan prices so promotions (Black Friday, etc.) apply to everyone
        $paymentType = $data['payment_type'] ?? 'subscription';
        if (!in_array($paymentType, [], true)) {
            $plan = $subscription->plan;
            $data['amount'] = match ($paymentType) {
                'onboarding' => (float) ($plan?->onboarding_fee_usd ?? 0),
                'subscription', 'renewal' => $subscription->billing_cycle === 'yearly'
                    ? (float) ($plan?->price_yearly_usd ?? 0)
                    : (float) ($plan?->price_monthly_usd ?? 0),
                default => (float) ($data['amount'] ?? 0),
            };
        }

        // Validate authoritative amount in USD before applying any discounts
        $data['currency'] = 'USD';
        $this->paymentValidator->validatePaymentAmount($subscription, $data);

        // Apply pending referral discount directly (no pre-existing credit needed)
        $referralDiscount = 0;
        if (!in_array($paymentType, [], true)) {
            $referral = Referral::where('subscription_id', $subscription->id)
                ->where('status', ReferralStatus::PENDING)
                ->first();
            if ($referral && (float) $referral->discount_applied > 0) {
                $referralDiscount = min((float) $referral->discount_applied, (float) $data['amount']);
                $data['amount'] = round((float) $data['amount'] - $referralDiscount, 2);
                $referral->update(['status' => ReferralStatus::APPLIED]);
            }
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
                    'original_amount' => $originalAmount,
                    'referral_discount_applied' => $referralDiscount,
                    'credit_used' => $creditResult['credit_used'] ?? 0,
                    'credit_application_ids' => array_map(fn ($a) => $a->id, $creditApplications),
                ]
            ),
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
            // Link credit applications to the payment
            if (!empty($creditApplications)) {
                foreach ($creditApplications as $app) {
                    $app->update(['billing_payment_id' => $payment->id]);
                }
            }

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

            // Reverse credit consumption since the payment failed
            if (!empty($creditApplications)) {
                $this->creditService->reverseApplications($creditApplications);
            }

            Log::error('[GatewayService] Initiation failed', [
                'payment_id' => $payment->id,
                'gateway' => $gatewayName,
                'error' => $e->getMessage(),
                'credit_reversed' => !empty($creditApplications),
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

        $this->handlePaymentType($payment);
    }
}
