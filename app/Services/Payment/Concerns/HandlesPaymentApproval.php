<?php

namespace App\Services\Payment\Concerns;

use App\Models\BillingPayment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\ModuleAccessService;
use App\Events\SubscriptionPaymentCompletedForAccounting;
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

        // Reconcile the subscription onto the plan the user actually paid for.
        // Onboarding metadata carries plan_id; subscription payments (reactivate,
        // subscribe, resubscribe) carry to_plan_id. Without this a reactivated
        // subscription would keep the OLD plan it held before suspension.
        // upgrade_proration already switches plans inside its own transaction.
        if ($paymentType === 'onboarding' || $paymentType === 'subscription') {
            $subscription = $this->applyPaidPlan($subscription, $payment);
        }

        match ($paymentType) {
            'onboarding' => $this->subscriptionService->activateAfterOnboarding($subscription),
            'subscription' => $this->handleSubscriptionPayment($subscription, $payment),
            'renewal' => $this->handleRenewalPayment($subscription, $payment),
            'topup' => $this->handleTopUpPayment($subscription, $payment),
            'upgrade_proration' => $this->handleUpgradeProration($payment, $subscription),
            'billing_cycle_change' => $this->handleBillingCycleChange($payment, $subscription),
            default => null,
        };

        $this->logSubscriptionAuditState($subscription, $payment, $paymentType);
    }

    /**
     * Move the subscription onto the plan encoded in the payment metadata whenever
     * the user paid for a plan different from the one the subscription currently
     * holds. Returns the fresh instance once the plan changed.
     */
    private function applyPaidPlan(Subscription $subscription, BillingPayment $payment): Subscription
    {
        $metadata = $payment->metadata ?? [];
        $targetPlanId = $metadata['to_plan_id'] ?? $metadata['plan_id'] ?? null;
        if ($targetPlanId && (int) $targetPlanId !== (int) $subscription->plan_id) {
            $billingCycle = in_array($metadata['billing_cycle'] ?? null, ['monthly', 'yearly'], true)
                ? $metadata['billing_cycle']
                : null;
            $this->subscriptionService->changePlan($subscription, (int) $targetPlanId, $billingCycle);
            return $subscription->fresh();
        }
        return $subscription;
    }

    /**
     * Single audit trail line capturing the resulting subscription state after ANY
     * payment type is applied, so the charged amount and the coverage granted can
     * be verified back-to-back (money done, coverage done).
     */
    private function logSubscriptionAuditState(Subscription $subscription, BillingPayment $payment, string $paymentType): void
    {
        if (!$subscription) {
            return;
        }

        Log::info('[SubscriptionAudit] state after payment', [
            'payment_id' => $payment->id,
            'payment_type' => $paymentType,
            'subscription_id' => $subscription->id,
            'business_id' => $subscription->business_id,
            'plan_id' => $subscription->plan_id,
            'plan_name' => $subscription->plan?->name,
            'billing_cycle' => $subscription->billing_cycle,
            'status' => $subscription->status,
            'next_billing_date' => $subscription->next_billing_date?->toDateString(),
            'trial_ends_at' => $subscription->trial_ends_at?->toDateString(),
            'ends_at' => $subscription->ends_at?->toDateString(),
            'credit_used' => (float) ($payment->metadata['credit_used'] ?? 0),
            'topup_months' => $payment->metadata['topup_months'] ?? null,
            'amount_paid' => (float) $payment->amount,
        ]);
    }

    private function handleRenewalPayment(Subscription $subscription, BillingPayment $payment): void
    {
        $this->persistPaidBillingCycle($subscription, $payment);

        // An early renewal (paid while the current period is still running) extends
        // the existing next_billing_date instead of resetting it from today.
        if ($subscription->next_billing_date?->isFuture() ?? false) {
            $this->subscriptionService->renewEarly($subscription);
            return;
        }

        $this->subscriptionService->renewSubscription($subscription, $payment);
    }

    private function handleTopUpPayment(Subscription $subscription, BillingPayment $payment): void
    {
        $this->persistPaidBillingCycle($subscription, $payment);

        $months = (int) ($payment->metadata['topup_months'] ?? 1);

        $this->subscriptionService->renewEarly($subscription, $months);
    }

    /**
     * A 'subscription' payment activates a fresh/onboarding subscription, but a
     * suspended one resumes via reactivate (activateSubscription forbids suspended).
     */
    private function handleSubscriptionPayment(Subscription $subscription, BillingPayment $payment): void
    {
        $this->persistPaidBillingCycle($subscription, $payment);

        if ($subscription->status === \App\Enums\Billing\SubscriptionStatus::SUSPENDED) {
            $this->subscriptionService->reactivate($subscription);
            return;
        }

        $this->subscriptionService->activateSubscription($subscription, $payment, null);
    }

    /**
     * When a user paid for a billing cycle different from the subscription's stored
     * one (e.g. resubscribing yearly while the subscription was stored as monthly),
     * persist the paid cycle so renewals and next_billing_date stay consistent.
     */
    private function persistPaidBillingCycle(Subscription $subscription, BillingPayment $payment): void
    {
        $paidCycle = $payment->metadata['billing_cycle'] ?? null;
        if (!in_array($paidCycle, ['monthly', 'yearly'], true)) {
            return;
        }

        $currentCycle = $subscription->billing_cycle ?? 'monthly';
        if ($paidCycle === $currentCycle) {
            return;
        }

        try {
            $this->subscriptionService->applyBillingCycleChange($subscription, $paidCycle);
        } catch (\RuntimeException $e) {
            Log::warning('[GatewayService] Could not persist paid billing cycle', [
                'subscription_id' => $subscription->id,
                'payment_id' => $payment->id,
                'paid_cycle' => $paidCycle,
                'error' => $e->getMessage(),
            ]);
        }
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
            unset($metadata['pending_cycle_change_amount_usd']);

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
        // The payment metadata's billing_cycle is defaulted by GatewayService to the
        // subscription's *current* cycle at initiation. The cycle the user actually
        // paid for on the upgrade is recorded on the subscription during upgrade().
        $billingCycle = $subscription->metadata['pending_upgrade_billing_cycle']
            ?? $metadata['billing_cycle']
            ?? $subscription->billing_cycle
            ?? 'monthly';

        if (!$toPlanId) {
            Log::warning('[GatewayService] Upgrade payment missing to_plan_id metadata', [
                'payment_id' => $payment->id,
            ]);
            return;
        }

        // The upgrade quote already charged the full-window difference over the
        // REMAINING prepaid coverage (top-ups keep next_billing_date far out).
        // Preserve the paid-through date so topped-up days are never discarded;
        // only a cycle switch (monthly → yearly prepay) resets it.
        $preserveBillingDate = $billingCycle === ($subscription->billing_cycle ?? 'monthly');

        DB::transaction(function () use ($payment, $subscription, $toPlanId, $billingCycle, $preserveBillingDate) {
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

            $this->subscriptionService->changePlan(
                $subscription,
                (int) $toPlanId,
                $billingCycle,
                $preserveBillingDate,
            );

            $this->referralService->activateForSubscription($subscription->id);

            $meta = $subscription->metadata ?? [];
            unset($meta['pending_upgrade_amount_usd']);
            unset($meta['pending_upgrade_to_plan_id']);
            unset($meta['pending_upgrade_billing_cycle']);
            $subscription->update(['metadata' => $meta]);

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

                    // A business upgraded from personal has no branch yet - ensure the
                    // default branch exists (and backfills orphaned location_id rows).
                    \App\Services\LocationService::ensureDefault($subscription->business_id);
                }
            }
        });

        Log::info('[GatewayService] Upgrade completed via payment', [
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'to_plan_id' => $toPlanId,
            'preserved_next_billing_date' => $preserveBillingDate,
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

            $this->sendReceiptIfDue($payment);

            // The completed payment is the source of truth for money received.
            // Dispatch the company-books automation from here (idempotent per
            // payment) so the Custospark ledger always matches what was paid.
            event(new SubscriptionPaymentCompletedForAccounting($payment));

            Log::info('[PaymentAudit] payment confirmed', [
                'payment_id' => $payment->id,
                'gateway' => $payment->gateway_name,
                'subscription_id' => $payment->subscription_id,
                'business_id' => $payment->business_id,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'payment_type' => $payment->payment_type,
                'gateway_confirmed_amount' => isset($verification['amount']) ? (float) $verification['amount'] : null,
                'gateway_confirmed_currency' => $verification['currency'] ?? null,
            ]);

            Log::info('[GatewayService] Payment auto-approved', [
                'payment_id' => $payment->id,
                'gateway' => $payment->gateway_name,
                'subscription_id' => $payment->subscription_id,
                'business_id' => $payment->business_id,
            ]);
        });
    }

    private function sendReceiptIfDue(BillingPayment $payment): void
    {
        $this->receiptService->sendReceiptIfDue($payment);
    }
}
