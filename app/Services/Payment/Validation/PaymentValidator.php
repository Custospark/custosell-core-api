<?php

namespace App\Services\Payment\Validation;

use App\Models\BillingPayment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\Currency\Contracts\CurrencyExchangeServiceInterface;
use App\Services\Payment\Gateways\Exceptions\GatewayException;

class PaymentValidator
{
    public function __construct(
        private readonly CurrencyExchangeServiceInterface $currencyExchange,
        private readonly PaymentRepositoryInterface $paymentRepo,
    ) {}

    public function validatePaymentAmount(Subscription $subscription, array $data): void
    {
        $paymentType = $data['payment_type'] ?? 'subscription';
        $amount = (float) ($data['amount'] ?? 0);
        $currency = strtoupper($data['currency'] ?? 'USD');
        $effectiveCycle = in_array($paymentType, ['renewal', 'topup'], true)
            ? ($subscription->billing_cycle ?? 'monthly')
            : (in_array($data['billing_cycle'] ?? null, ['monthly', 'yearly'], true)
                ? $data['billing_cycle']
                : ($subscription->billing_cycle ?? 'monthly'));

        $plan = $subscription->plan;

        // The charge is for the plan in metadata whenever the user is moving onto a
        // specific plan (onboarding: plan_id; subscription reactivate/subscribe:
        // to_plan_id). Otherwise a reactivation priced against the old plan would
        // be rejected, or worse, a mismatched payment validated against the wrong plan.
        if (in_array($paymentType, ['onboarding', 'subscription'], true)) {
            $metaPlanId = $data['metadata']['to_plan_id'] ?? $data['metadata']['plan_id'] ?? null;
            if ($metaPlanId && (int) $metaPlanId !== $subscription->plan_id) {
                $target = Plan::find((int) $metaPlanId);
                if ($target) {
                    $plan = $target;
                }
            }
        }

        if ($paymentType === 'topup') {
            $topupMonths = (int) ($data['topup_months'] ?? 0);
            $monthlyRate = $effectiveCycle === 'yearly'
                ? ((float) ($plan?->price_yearly_usd ?? 0) / 12)
                : (float) ($plan?->price_monthly_usd ?? 0);
            $expectedUsd = round($topupMonths * $monthlyRate, 2);
        } else {
            $expectedUsd = match ($paymentType) {
                'onboarding' => (float) ($plan?->onboarding_fee_usd ?? 0),
                'subscription' => $effectiveCycle === 'yearly'
                    ? (float) ($plan?->price_yearly_usd ?? 0)
                    : (float) ($plan?->price_monthly_usd ?? 0),
                'renewal' => $effectiveCycle === 'yearly'
                    ? (float) ($plan?->price_yearly_usd ?? 0)
                    : (float) ($plan?->price_monthly_usd ?? 0),
                'upgrade_proration' => (float) ($subscription->metadata['pending_upgrade_amount_usd'] ?? $amount),
                'billing_cycle_change' => (float) ($subscription->metadata['pending_cycle_change_amount_usd'] ?? $amount),
                default => $amount,
            };
        }

        if ($currency === 'USD') {
            $expected = $expectedUsd;
            $tolerance = 0.50;
        } else {
            $rate = $this->currencyExchange->getExchangeRate('USD', $currency);
            if ($rate === null) {
                throw new GatewayException(
                    "Cannot validate payment: exchange rate unavailable for {$currency}.",
                    $data['gateway_name'] ?? 'unknown',
                    ['payment_type' => $paymentType, 'currency' => $currency]
                );
            }
            $expected = round($expectedUsd * $rate, 2);
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

    public function resolvePaymentFromWebhook(array $webhookData): ?BillingPayment
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
