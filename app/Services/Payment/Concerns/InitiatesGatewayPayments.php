<?php

namespace App\Services\Payment\Concerns;

use App\Models\BillingPayment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait InitiatesGatewayPayments
{
    private function initiateWithDriver(
        PaymentGatewayInterface $driver,
        array $data,
        BillingPayment $payment,
        Subscription $subscription,
        ?Plan $plan,
        string $gatewayName,
        string $ourRef,
        string $countryCode,
        float $originalAmount,
        array $creditApplications,
    ): array {
        $paymentType = $data['payment_type'] ?? 'subscription';
        $effectiveCycle = $paymentType === 'renewal'
            ? ($subscription->billing_cycle ?? 'monthly')
            : (in_array($data['billing_cycle'] ?? null, ['monthly', 'yearly'], true)
                ? $data['billing_cycle']
                : ($subscription->billing_cycle ?? 'monthly'));
        $cycleSuffix = $paymentType === 'onboarding'
            ? ''
            : ' (' . ($effectiveCycle === 'yearly' ? 'yearly' : 'monthly') . ')';
        $typeLabel = match ($paymentType) {
            'onboarding' => 'onboarding',
            'renewal' => 'renewal',
            default => 'subscription',
        };

        $driverPayload = [
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency']),
            'our_reference' => $ourRef,
            'phone_number' => $data['phone_number'] ?? null,
            'email' => $data['email'] ?? null,
            'customer_name' => $data['customer_name'] ?? null,
            'description' => 'Custosell - ' . ($plan?->name ?? 'Plan') . ' ' . $typeLabel . $cycleSuffix,
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

            // TEMP DEBUG: amount sent to provider at initiation - remove after test
            \Illuminate\Support\Facades\Log::info('[DEBUG][GatewayService] Initiate payload to provider', [
                'amount' => $driverPayload['amount'],
                'currency' => $driverPayload['currency'],
                'payment_id' => $payment->id,
                'subscription_id' => $subscription->id,
                'business_id' => $subscription->business_id,
                'payment_type' => $paymentType,
                'billing_cycle' => $effectiveCycle,
                'plan_id' => $plan?->id,
                'plan_name' => $plan?->name,
                'original_amount_usd' => $originalAmount,
                'our_reference' => $ourRef,
                'email' => $driverPayload['email'],
                'phone_number' => $driverPayload['phone_number'],
                'first_name' => $driverPayload['customer_name'],
                'description' => $driverPayload['description'],
                'country_code' => $countryCode,
            ]);

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
                    $this->sendReceiptIfDue($payment);

                    // The completed payment is the source of truth for money
                    // received - journal directly on the dev bypass path too
                    // (same as HandlesPaymentApproval::autoApprove).
                    try {
                        $this->companyAccounting->accountForSubscriptionPayment($payment);
                    } catch (\Throwable $e) {
                        Log::error("Company books: failed to journal subscription payment {$payment->id}: {$e->getMessage()}", [
                            'payment_id' => $payment->id,
                            'exception' => $e,
                        ]);
                    }
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
}
