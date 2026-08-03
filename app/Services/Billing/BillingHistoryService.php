<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use App\Models\Subscription;
use Illuminate\Support\Collection;

/**
 * Builds a unified, newest-first billing activity timeline for a subscription.
 *
 * Merges payments, scheduled plan changes, and credit applications into a
 * single feed so the History tab answers every billing question in order.
 */
class BillingHistoryService
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function feed(Subscription $subscription): Collection
    {
        $items = collect([]);

        $subscription->payments->each(function (BillingPayment $payment) use ($items) {
            $items->push($this->paymentItem($payment));
        });

        $subscription->scheduledChanges()
            ->with(['fromPlan', 'toPlan'])
            ->get()
            ->each(function ($change) use ($items) {
                $items->push($this->changeItem($change));
            });

        $subscription->creditApplications()
            ->with('credit')
            ->get()
            ->each(function ($application) use ($items) {
                $items->push($this->creditItem($application));
            });

        return $items->filter()->values()->sortByDesc(fn (array $item) => $item['at'])->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function paymentItem(BillingPayment $payment): ?array
    {
        $type = $payment->payment_type?->value ?? 'subscription';
        $labels = [
            'onboarding' => 'Setup fee paid',
            'subscription' => 'Subscription payment',
            'renewal' => 'Renewal payment',
            'topup' => 'Early renewal top-up',
            'upgrade_proration' => 'Upgrade payment',
            'billing_cycle_change' => 'Billing cycle change payment',
        ];

        return [
            'type' => 'payment',
            'event' => $labels[$type] ?? ucfirst(str_replace('_', ' ', $type)),
            'status' => $payment->status?->value ?? 'pending',
            'amount' => (float) $payment->amount,
            'currency' => strtoupper($payment->currency ?? 'USD'),
            'payment_id' => $payment->id,
            'payment_type' => $type,
            'method' => $payment->method?->value ?? null,
            'transaction_reference' => $payment->transaction_reference,
            'topup_months' => (int) ($payment->metadata['topup_months'] ?? 0),
            'credit_used' => (float) ($payment->metadata['credit_used'] ?? 0),
            'at' => ($payment->paid_at ?? $payment->created_at)?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function changeItem($change): array
    {
        $type = $change->change_type?->value ?? 'change';

        return [
            'type' => 'change',
            'event' => ucfirst(str_replace('_', ' ', $type)),
            'description' => $change->change_type?->value,
            'status_override' => $change->status?->value,
            'id' => $change->id,
            'status' => 'change',
            'amount' => 0,
            'currency' => null,
            'change_type' => $type,
            'from_plan' => $change->fromPlan?->name,
            'to_plan' => $change->toPlan?->name,
            'effective_at' => $change->effective_at?->toISOString(),
            'at' => ($change->effective_at ?? $change->created_at)?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function creditItem($application): array
    {
        return [
            'type' => 'credit',
            'event' => 'Credit applied',
            'description' => 'Used billing credit toward a payment',
            'amount' => -(float) $application->amount_applied,
            'currency' => 'USD',
            'credit_id' => $application->credit_id,
            'at' => $application->applied_at?->toISOString(),
        ];
    }
}