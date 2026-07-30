<?php

namespace App\Services\Payment\Validation;

use App\Models\BillingPayment;
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

        $plan = $subscription->plan;
        $expectedUsd = match ($paymentType) {
            'onboarding' => (float) ($plan?->onboarding_fee_usd ?? 0),
            'subscription' => $subscription->billing_cycle === 'yearly'
                ? (float) ($plan?->price_yearly_usd ?? 0)
                : (float) ($plan?->price_monthly_usd ?? 0),
            'renewal' => $subscription->billing_cycle === 'yearly'
                ? (float) ($plan?->price_yearly_usd ?? 0)
                : (float) ($plan?->price_monthly_usd ?? 0),
            'upgrade_proration' => (float) ($subscription->metadata['pending_upgrade_amount_usd'] ?? $amount),
            'billing_cycle_change' => (float) ($subscription->metadata['pending_cycle_change_amount_usd'] ?? $amount),
            default => $amount,
        };

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
