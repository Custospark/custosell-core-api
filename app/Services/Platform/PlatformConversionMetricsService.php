<?php

namespace App\Services\Platform;

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Subscription;
use Carbon\Carbon;

/**
 * Platform-wide trial-to-paid conversion analytics. A subscription is
 * "converted" when it first reaches ACTIVE status (converted_at). Because new
 * registrations always start on trial (ADR-035), "trials started" is measured
 * by subscription creation.
 */
class PlatformConversionMetricsService
{
    private const MONTHS = 12;

    /**
     * @return array<string, mixed>
     */
    public function conversionDashboard(?Carbon $rangeFrom = null, ?Carbon $rangeTo = null): array
    {
        $rangeFrom ??= now()->subDays(29)->startOfDay();
        $rangeTo ??= now()->endOfDay();

        if ($rangeFrom->gt($rangeTo)) {
            [$rangeFrom, $rangeTo] = [$rangeTo->copy()->startOfDay(), $rangeFrom->copy()->endOfDay()];
        }

        $todayStart = now()->startOfDay();
        $weekStart = now()->startOfWeek();
        $monthStart = now()->startOfMonth();

        $trialsToday = Subscription::where('created_at', '>=', $todayStart)->count();
        $trialsThisWeek = Subscription::where('created_at', '>=', $weekStart)->count();
        $trialsThisMonth = Subscription::where('created_at', '>=', $monthStart)->count();
        $trialsInRange = Subscription::whereBetween('created_at', [$rangeFrom, $rangeTo])->count();

        $convertedToday = Subscription::where('converted_at', '>=', $todayStart)->count();
        $convertedThisWeek = Subscription::where('converted_at', '>=', $weekStart)->count();
        $convertedThisMonth = Subscription::where('converted_at', '>=', $monthStart)->count();
        $convertedInRange = Subscription::whereBetween('converted_at', [$rangeFrom, $rangeTo])->count();

        $activeNow = Subscription::where('status', SubscriptionStatus::ACTIVE)->count();
        $onTrialNow = Subscription::where('status', SubscriptionStatus::TRIAL)->count();
        $pastDueNow = Subscription::where('status', SubscriptionStatus::PAST_DUE)->count();
        $cancelledNow = Subscription::where('status', SubscriptionStatus::CANCELLED)->count();
        $suspendedNow = Subscription::where('status', SubscriptionStatus::SUSPENDED)->count();

        return [
            'summary' => [
                'trials_started' => [
                    'today' => $trialsToday,
                    'this_week' => $trialsThisWeek,
                    'this_month' => $trialsThisMonth,
                    'in_range' => $trialsInRange,
                ],
                'converted' => [
                    'today' => $convertedToday,
                    'this_week' => $convertedThisWeek,
                    'this_month' => $convertedThisMonth,
                    'in_range' => $convertedInRange,
                ],
                'conversion_rate' => $this->rate($convertedInRange, $trialsInRange),
                'status_now' => [
                    'active' => $activeNow,
                    'on_trial' => $onTrialNow,
                    'past_due' => $pastDueNow,
                    'cancelled' => $cancelledNow,
                    'suspended' => $suspendedNow,
                ],
                'range_from' => $rangeFrom->toDateString(),
                'range_to' => $rangeTo->toDateString(),
            ],
            'monthly' => $this->monthlySeries(),
            'by_plan' => $this->byPlan($rangeFrom, $rangeTo),
            'decisions' => $this->decisions($convertedInRange, $trialsInRange, $activeNow, $onTrialNow),
        ];
    }

    /**
     * Last 12 months of trials started and converted, plus the per-month rate.
     *
     * @return list<array<string, mixed>>
     */
    private function monthlySeries(): array
    {
        $series = [];
        $cursor = now()->startOfMonth()->subMonths(self::MONTHS - 1);

        for ($i = 0; $i < self::MONTHS; $i++) {
            $from = $cursor->copy();
            $to = $cursor->copy()->endOfMonth();

            $trials = Subscription::whereBetween('created_at', [$from, $to])->count();
            $converted = Subscription::whereBetween('converted_at', [$from, $to])->count();

            $series[] = [
                'month' => $from->format('Y-m'),
                'label' => $from->format('M y'),
                'trials_started' => $trials,
                'converted' => $converted,
                'conversion_rate' => $this->rate($converted, $trials),
            ];

            $cursor->addMonth();
        }

        return $series;
    }

    /**
     * Trial and conversion counts grouped by plan over the selected range.
     *
     * @return list<array<string, mixed>>
     */
    private function byPlan(Carbon $rangeFrom, Carbon $rangeTo): array
    {
        return Subscription::query()
            ->with('plan:id,name,slug')
            ->whereBetween('created_at', [$rangeFrom, $rangeTo])
            ->get()
            ->groupBy(fn (Subscription $subscription) => $subscription->plan?->slug ?? 'unknown')
            ->map(function ($subscriptions, string $slug) use ($rangeFrom, $rangeTo) {
                $first = $subscriptions->first();
                $trials = $subscriptions->count();
                $converted = $subscriptions->filter(
                    fn (Subscription $subscription) => $subscription->converted_at !== null
                        && $subscription->converted_at->between($rangeFrom, $rangeTo)
                )->count();

                return [
                    'plan_slug' => $slug,
                    'plan_name' => $first?->plan?->name ?? 'Unknown',
                    'trials_started' => $trials,
                    'converted' => $converted,
                    'conversion_rate' => $this->rate($converted, $trials),
                ];
            })
            ->values()
            ->sortByDesc('trials_started')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function decisions(int $convertedInRange, int $trialsInRange, int $activeNow, int $onTrialNow): array
    {
        $decisions = [];

        $decisions[] = $trialsInRange > 0
            ? "{$trialsInRange} trial(s) started in range, {$convertedInRange} converted - {$this->rate($convertedInRange, $trialsInRange)}% rate."
            : 'No trials started in the selected range.';

        $decisions[] = $activeNow > 0
            ? "{$activeNow} active subscriber(s) platform-wide."
            : 'No active subscribers platform-wide yet.';

        $decisions[] = $onTrialNow > 0
            ? "{$onTrialNow} trial(s) currently running - nurture them toward conversion."
            : 'No trials currently running.';

        return $decisions;
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : 0.0;
    }
}