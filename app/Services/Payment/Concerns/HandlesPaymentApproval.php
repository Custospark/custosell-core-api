<?php

namespace App\Services\Payment\Concerns;

use App\Models\BillingPayment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\ModuleAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait HandlesPaymentApproval
{
    private function handlePaymentType(BillingPayment $payment): void
    {
        $paymentType = $payment->payment_type instanceof \App\Enums\Billing\PaymentType
            ? $payment->payment_type->value
            : $payment->payment_type;

        $subscription = $payment->subscription;

        // For onboarding payments, apply any pending plan upgrade
        // stored in metadata before activating the subscription.
        if ($paymentType === 'onboarding') {
            $metadata = $payment->metadata ?? [];
            $planId = $metadata['plan_id'] ?? null;
            if ($planId && (int) $planId !== $subscription->plan_id) {
                $this->subscriptionService->changePlan($subscription, (int) $planId);
                $subscription = $subscription->fresh();
            }
        }

        match ($paymentType) {
            'onboarding' => $this->subscriptionService->activateAfterOnboarding($subscription),
            'subscription' => $this->subscriptionService->activateSubscription($subscription, $payment, null),
            'renewal' => $this->subscriptionService->renewSubscription($subscription, $payment),
            'upgrade_proration' => $this->handleUpgradeProration($payment, $subscription),
            'billing_cycle_change' => $this->handleBillingCycleChange($payment, $subscription),
            default => null,
        };
    }

    private function handleBillingCycleChange(BillingPayment $payment, Subscription $subscription): void
    {
        $pendingCycle = $subscription->metadata['pending_billing_cycle'] ?? null;
        if (!$pendingCycle || !in_array($pendingCycle, ['monthly', 'yearly'], true)) {
            Log::warning('[GatewayService] Billing cycle change payment missing pending_billing_cycle in metadata', [
                'payment_id' => $payment->id,
                'subscription_id' => $subscription->id,
            ]);
            return;
        }

        DB::transaction(function () use ($payment, $subscription, $pendingCycle) {
            $this->scheduledChangeRepo->create([
                'subscription_id' => $subscription->id,
                'business_id' => $subscription->business_id,
                'change_type' => 'billing_cycle_change',
                'from_plan_id' => $subscription->plan_id,
                'to_plan_id' => $subscription->plan_id,
                'effective_at' => now(),
                'status' => 'applied',
                'proration_amount' => $payment->amount,
                'metadata' => [
                    'source' => 'payment_webhook',
                    'payment_id' => $payment->id,
                ],
            ]);

            $metadata = $subscription->metadata ?? [];
            unset($metadata['pending_billing_cycle']);

            $this->subscriptionService->applyBillingCycleChange(
                $subscription,
                $pendingCycle,
                $metadata,
            );
        });

        Log::info('[GatewayService] Billing cycle change completed via payment', [
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'new_billing_cycle' => $pendingCycle,
        ]);
    }

    private function handleUpgradeProration(BillingPayment $payment, Subscription $subscription): void
    {
        $metadata = $payment->metadata ?? [];
        $toPlanId = $metadata['to_plan_id'] ?? null;

        if (!$toPlanId) {
            Log::warning('[GatewayService] Upgrade payment missing to_plan_id metadata', [
                'payment_id' => $payment->id,
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

            $plan = Plan::find($toPlanId);
            if ($plan && $plan->type !== 'personal') {
                $business = $subscription->business;
                $owner = $business->owner;
                if ($owner && $owner->account_type === 'personal') {
                    $owner->update([
                        'account_type' => 'business',
                        'modules' => ModuleAccessService::BUSINESS_MODULES,
                    ]);
                    $business->update(['business_type' => 'retail']);
                }
            }
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
}
