<?php

namespace App\Services\Payment;

use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\Contracts\PaymentServiceInterface;
use App\Services\Contracts\SubscriptionServiceInterface;
use App\Services\Payment\Gateways\Exceptions\GatewayException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GatewayService
{
    public function __construct(
        private readonly GatewayManager $gatewayManager,
        private readonly PaymentRepositoryInterface $paymentRepo,
        private readonly PaymentServiceInterface $paymentService,
        private readonly SubscriptionServiceInterface $subscriptionService,
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
        ]);

        $plan = $subscription->plan;
        $ourRef = "CUSTOSELL-{$payment->id}-" . now()->format('YmdHis');

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

            $paymentType = $payment->payment_type instanceof \App\Enums\Billing\PaymentType
                ? $payment->payment_type->value
                : $payment->payment_type;

            match ($paymentType) {
                'onboarding' => $this->subscriptionService->activateAfterOnboarding(
                    $payment->subscription
                ),
                'subscription' => $this->subscriptionService->activateSubscription(
                    $payment->subscription, $payment, null
                ),
                'renewal' => $this->subscriptionService->renewSubscription(
                    $payment->subscription, $payment
                ),
                default => null,
            };

            Log::info('[GatewayService] Payment auto-approved', [
                'payment_id' => $payment->id,
                'gateway' => $payment->gateway_name,
                'subscription_id' => $payment->subscription_id,
                'business_id' => $payment->business_id,
            ]);
        });
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
