<?php

namespace App\Services\Platform;

use App\Models\Business;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Analytics and transformation for platform businesses (gross sales, activity, onboarding dashboard).
 */
class PlatformBusinessMetricsService
{
    public function __construct(
        protected PlatformBusinessQueryBuilder $queries,
    ) {}

    protected function activityWindowDays(): int
    {
        return (int) config('platform.activity_window_days', 30);
    }

    protected function activityDormantDays(): int
    {
        return max($this->activityWindowDays(), (int) config('platform.activity_dormant_days', 90));
    }

    /** @return list<string> */
    protected function blockedStatuses(): array
    {
        return config('platform.blocked_business_statuses', ['restricted', 'suspended']);
    }

    /**
     * Gross sales (SUM of sale totals) per business for the activity window.
     *
     * @return Collection<int, array{business: Business, gross_sales_30d: float}>
     */
    public function businessesWithGrossSales30d(?Carbon $windowStart = null): Collection
    {
        $windowStart ??= now()->subDays($this->activityWindowDays());

        $businesses = $this->queries->businessMetricsQuery($windowStart)->get();
        $this->queries->hydrateOwners($businesses);

        return $businesses->map(function (Business $business) use ($windowStart) {
            $gross30d = (float) ($business->getAttributes()['gross_sales_30d'] ?? 0);
            $business->gross_sales_30d = $gross30d;

            return [
                'business' => $business,
                'gross_sales_30d' => $gross30d,
                'row' => $this->transformBusiness($business, $windowStart),
            ];
        });
    }

    /**
     * Five equal-width gross-sales bands per currency (lowest → highest).
     *
     * @return list<array{currency: string, tiers: list<array<string, mixed>>, decision_note: string}>
     */
    public function grossIncomeDistribution(?Carbon $windowStart = null, int $tierCount = 5): array
    {
        $windowStart ??= now()->subDays($this->activityWindowDays());
        $rows = $this->businessesWithGrossSales30d($windowStart);

        return $rows
            ->groupBy(fn (array $entry) => $entry['business']->currency ?? 'UGX')
            ->map(function (Collection $currencyRows, string $currency) use ($tierCount) {
                $amounts = $currencyRows->pluck('gross_sales_30d')->map(fn ($v) => (float) $v);
                $min = (float) $amounts->min();
                $max = (float) $amounts->max();
                $tiers = $this->buildGrossTiers($currencyRows, $min, $max, $tierCount, $currency);

                $withSales = $currencyRows->filter(fn (array $e) => $e['gross_sales_30d'] > 0)->count();
                $totalBusinesses = $currencyRows->count();
                $topTier = collect($tiers)->last();
                $bottomTier = collect($tiers)->first();

                $decisionNote = $withSales === 0
                    ? "No {$currency} businesses recorded gross sales in the last {$this->activityWindowDays()} days."
                    : sprintf(
                        '%d of %d %s businesses had gross sales (30d). %d%% sit in the lowest tier — consider an entry plan. %d%% are in the top tier — candidates for premium pricing.',
                        $withSales,
                        $totalBusinesses,
                        $currency,
                        $totalBusinesses > 0 ? (int) round(($bottomTier['business_count'] / $totalBusinesses) * 100) : 0,
                        $totalBusinesses > 0 ? (int) round(($topTier['business_count'] / $totalBusinesses) * 100) : 0,
                    );

                return [
                    'currency' => $currency,
                    'tiers' => $tiers,
                    'decision_note' => $decisionNote,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function transformBusiness(Business $business, ?Carbon $windowStart = null): array
    {
        $windowStart ??= now()->subDays($this->activityWindowDays());

        $activity = $this->resolveActivityProfile($business, $windowStart);

        $owner = $this->queries->resolveOwner($business);

        $gross30d = (float) ($business->getAttributes()['gross_sales_30d'] ?? $business->gross_sales_30d ?? 0);

        return [
            'id' => $business->id,
            'name' => $business->name,
            'slug' => $business->slug,
            'email' => $business->email,
            'currency' => $business->currency,
            'status' => $business->status,
            'status_changed_at' => $business->status_changed_at?->toIso8601String(),
            'activity_status' => $activity['activity_status'],
            'last_sale_at' => $activity['last_sale_at'],
            'last_login_at' => $activity['last_login_at'],
            'last_activity_at' => $activity['last_activity_at'],
            'days_since_activity' => $activity['days_since_activity'],
            'activity_active_days' => $activity['activity_active_days'],
            'activity_dormant_days' => $activity['activity_dormant_days'],
            'owner_name' => $owner?->name,
            'owner_email' => $owner?->email ?? $business->email,
            'owner_phone' => $owner?->phone ?? $business->phone,
            'plan_name' => $business->subscription?->plan?->name,
            'subscription_status' => $business->subscription?->status,
            'trial_ends_at' => $business->trial_ends_at?->toIso8601String(),
            'staff_count' => $this->queries->resolveStaffCount($business),
            'gross_sales_today' => $this->formatGross((float) ($business->getAttributes()['gross_sales_today'] ?? 0)),
            'gross_sales_7d' => $this->formatGross((float) ($business->getAttributes()['gross_sales_7d'] ?? 0)),
            'gross_sales_30d' => $this->formatGross($gross30d),
            'gross_sales_all_time' => $this->formatGross((float) ($business->getAttributes()['gross_sales_all_time'] ?? 0)),
            'transactions_30d' => (int) ($business->transactions_30d ?? 0),
            'total_stock' => (int) ($business->total_stock ?? 0),
            'created_at' => $business->created_at?->toIso8601String(),
        ];
    }

    /**
     * Onboarding and growth stats for the platform businesses dashboard.
     *
     * @return array<string, mixed>
     */
    public function onboardingDashboard(?Carbon $rangeFrom = null, ?Carbon $rangeTo = null): array
    {
        $rangeFrom ??= now()->subDays(29)->startOfDay();
        $rangeTo ??= now()->endOfDay();

        if ($rangeFrom->gt($rangeTo)) {
            [$rangeFrom, $rangeTo] = [$rangeTo->copy()->startOfDay(), $rangeFrom->copy()->endOfDay()];
        }

        $todayStart = now()->startOfDay();
        $weekStart = now()->startOfWeek();
        $monthStart = now()->startOfMonth();
        $windowStart = now()->subDays($this->activityWindowDays());

        $joinedToday = Business::where('created_at', '>=', $todayStart)->count();
        $joinedThisWeek = Business::where('created_at', '>=', $weekStart)->count();
        $joinedThisMonth = Business::where('created_at', '>=', $monthStart)->count();
        $joinedInRange = Business::whereBetween('created_at', [$rangeFrom, $rangeTo])->count();

        $totalBusinesses = Business::count();
        $suspendedCount = Business::where('status', 'suspended')->count();
        $warningCount = Business::where('status', 'warning')->count();
        $notifiedCount = Business::where('status', 'notified')->count();
        $restrictedCount = Business::where('status', 'restricted')->count();
        $activeStatusCount = Business::where('status', 'active')->count();

        $withGrossSales30d = $this->businessesWithGrossSales30d($windowStart)
            ->filter(fn (array $entry) => $entry['gross_sales_30d'] > 0)
            ->count();

        $platformTransactions30d = (int) Sale::where('sale_date', '>=', $windowStart)->count();
        $platformGrossSales30d = (float) Sale::where('sale_date', '>=', $windowStart)->sum('total_amount');

        $cumulativeBeforeRange = Business::where('created_at', '<', $rangeFrom)->count();
        $growth = [];
        $cursor = $rangeFrom->copy()->startOfDay();
        $end = $rangeTo->copy()->startOfDay();
        $runningTotal = $cumulativeBeforeRange;

        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $signups = Business::whereDate('created_at', $date)->count();
            $runningTotal += $signups;
            $growth[] = [
                'date' => $date,
                'signups' => $signups,
                'cumulative' => $runningTotal,
            ];
            $cursor->addDay();
        }

        $growthRateWeek = $joinedThisWeek > 0 && $totalBusinesses > 0
            ? round(($joinedThisWeek / max(1, $totalBusinesses - $joinedThisWeek)) * 100, 1)
            : 0.0;

        return [
            'onboarding' => [
                'today' => $joinedToday,
                'this_week' => $joinedThisWeek,
                'this_month' => $joinedThisMonth,
                'in_range' => $joinedInRange,
                'range_from' => $rangeFrom->toDateString(),
                'range_to' => $rangeTo->toDateString(),
            ],
            'totals' => [
                'total' => $totalBusinesses,
                'active_status' => $activeStatusCount,
                'warning' => $warningCount,
                'notified' => $notifiedCount,
                'restricted' => $restrictedCount,
                'suspended' => $suspendedCount,
                'with_gross_sales_30d' => $withGrossSales30d,
                'transactions_30d' => $platformTransactions30d,
                'gross_sales_30d' => number_format($platformGrossSales30d, 2, '.', ''),
            ],
            'growth' => $growth,
            'decisions' => [
                $joinedToday > 0
                    ? "{$joinedToday} new business(es) joined today — prioritize welcome onboarding."
                    : 'No new signups today — focus on re-activating dormant accounts.',
                $joinedThisMonth > 0
                    ? "{$joinedThisMonth} joined this month ({$growthRateWeek}% weekly growth vs existing base)."
                    : 'No new businesses this month — review acquisition channels.',
                $withGrossSales30d > 0
                    ? "{$withGrossSales30d} of {$totalBusinesses} businesses recorded sales in the last {$this->activityWindowDays()} days."
                    : "No businesses recorded sales in the last {$this->activityWindowDays()} days.",
                $platformTransactions30d > 0
                    ? number_format($platformTransactions30d).' sale transactions platform-wide in the last '.$this->activityWindowDays().' days.'
                    : 'No sale transactions in the activity window.',
            ],
        ];
    }

    /**
     * Activity is based on recency of the latest sale OR staff login — not lifetime sale volume.
     *
     * @return array{
     *     activity_status: string,
     *     last_sale_at: string|null,
     *     last_login_at: string|null,
     *     last_activity_at: string|null,
     *     days_since_activity: int|null,
     *     activity_active_days: int,
     *     activity_dormant_days: int,
     * }
     */
    private function resolveActivityProfile(Business $business, ?Carbon $windowStart = null): array
    {
        $activeDays = $this->activityWindowDays();
        $dormantDays = $this->activityDormantDays();
        $windowStart ??= now()->subDays($activeDays);

        $lastSaleAt = $business->last_sale_at ? Carbon::parse($business->last_sale_at) : null;
        $lastLoginAt = $business->last_user_login_at ? Carbon::parse($business->last_user_login_at) : null;
        $lastActivityAt = collect([$lastSaleAt, $lastLoginAt])->filter()->max();

        $daysSinceActivity = $lastActivityAt
            ? (int) $lastActivityAt->diffInDays(now())
            : null;

        $activityStatus = 'never_used';
        if (in_array($business->status, $this->blockedStatuses(), true)) {
            $activityStatus = 'suspended';
        } elseif ($lastActivityAt === null) {
            $activityStatus = 'never_used';
        } elseif ($daysSinceActivity <= $activeDays) {
            $activityStatus = 'active';
        } elseif ($daysSinceActivity <= $dormantDays) {
            $activityStatus = 'dormant';
        } else {
            $activityStatus = 'churned';
        }

        return [
            'activity_status' => $activityStatus,
            'last_sale_at' => $lastSaleAt?->toIso8601String(),
            'last_login_at' => $lastLoginAt?->toIso8601String(),
            'last_activity_at' => $lastActivityAt?->toIso8601String(),
            'days_since_activity' => $daysSinceActivity,
            'activity_active_days' => $activeDays,
            'activity_dormant_days' => $dormantDays,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function buildGrossTiers(Collection $currencyRows, float $min, float $max, int $tierCount, string $currency): array
    {
        $tierLabels = [
            1 => 'Tier 1 · Lowest earners',
            2 => 'Tier 2 · Low',
            3 => 'Tier 3 · Mid',
            4 => 'Tier 4 · High',
            5 => 'Tier 5 · Top earners',
        ];

        $step = $max > $min ? ($max - $min) / $tierCount : 0;
        $buckets = [];

        for ($i = 1; $i <= $tierCount; $i++) {
            $tierMin = $min + ($step * ($i - 1));
            $tierMax = $i === $tierCount ? $max : $min + ($step * $i);
            $buckets[$i] = [
                'tier' => $i,
                'label' => $tierLabels[$i] ?? "Tier {$i}",
                'min_gross' => $this->formatGross($tierMin),
                'max_gross' => $this->formatGross($tierMax),
                'business_count' => 0,
                'total_gross_sales_30d' => '0.00',
            ];
        }

        foreach ($currencyRows as $entry) {
            $amount = (float) $entry['gross_sales_30d'];
            $tier = $this->tierForAmount($amount, $min, $max, $tierCount);
            $buckets[$tier]['business_count']++;
            $buckets[$tier]['total_gross_sales_30d'] = $this->formatGross(
                (float) $buckets[$tier]['total_gross_sales_30d'] + $amount
            );
        }

        return array_values($buckets);
    }

    private function tierForAmount(float $amount, float $min, float $max, int $tierCount): int
    {
        if ($max <= $min) {
            return $amount > 0 ? $tierCount : 1;
        }

        $step = ($max - $min) / $tierCount;
        $tier = (int) floor(($amount - $min) / $step) + 1;

        return min($tierCount, max(1, $tier));
    }

    private function formatGross(float $amount): string
    {
        return number_format(max(0, $amount), 2, '.', '');
    }
}
