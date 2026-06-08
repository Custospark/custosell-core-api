<?php

namespace App\Services\Platform;

use App\Models\Business;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PlatformBusinessService
{
    public function __construct(
        protected PlatformNotificationService $notifications,
        protected PlatformAuditService $audit,
    ) {}

    public function activityWindowDays(): int
    {
        return (int) config('platform.activity_window_days', 30);
    }

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $windowStart = now()->subDays($this->activityWindowDays());
        $today = now()->toDateString();
        $sevenDaysAgo = now()->subDays(6)->startOfDay();

        $query = Business::query()
            ->with(['owner:id,name,email', 'subscription.plan:id,name'])
            ->withCount('users as staff_count')
            ->select('businesses.*')
            ->selectSub(function ($sub) use ($today) {
                $sub->from('sales')
                    ->selectRaw('COALESCE(SUM(total_amount), 0)')
                    ->whereColumn('sales.business_id', 'businesses.id')
                    ->whereDate('sale_date', $today);
            }, 'gross_sales_today')
            ->selectSub($this->grossSalesSubquery($sevenDaysAgo), 'gross_sales_7d')
            ->selectSub($this->grossSalesSubquery($windowStart), 'gross_sales_30d')
            ->selectSub($this->grossSalesSubquery(), 'gross_sales_all_time')
            ->selectSub(function ($sub) use ($windowStart) {
                $sub->from('sales')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('sales.business_id', 'businesses.id')
                    ->where('sale_date', '>=', $windowStart);
            }, 'transactions_30d')
            ->selectSub(function ($sub) {
                $sub->from('sales')
                    ->selectRaw('MAX(sale_date)')
                    ->whereColumn('sales.business_id', 'businesses.id');
            }, 'last_sale_at')
            ->selectSub(function ($sub) {
                $sub->from('users')
                    ->selectRaw('MAX(last_login_at)')
                    ->whereColumn('users.business_id', 'businesses.id');
            }, 'last_user_login_at');

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($search) {
                $q->where('businesses.name', 'like', $search)
                    ->orWhere('businesses.email', 'like', $search)
                    ->orWhereHas('owner', fn ($oq) => $oq->where('email', 'like', $search));
            });
        }

        if (! empty($filters['status'])) {
            $query->where('businesses.status', $filters['status']);
        }

        if (! empty($filters['currency'])) {
            $query->where('businesses.currency', $filters['currency']);
        }

        $sort = $filters['sort'] ?? 'gross_sales_30d';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($sort === 'name') {
            $query->orderBy('businesses.name', $direction);
        } elseif ($sort === 'created_at') {
            $query->orderBy('businesses.created_at', $direction);
        } else {
            $query->orderBy('gross_sales_30d', $direction);
        }

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(fn (Business $business) => $this->transformBusiness($business, $windowStart));

        return $paginator;
    }

    /**
     * Gross sales (SUM of sale totals) per business for the activity window.
     *
     * @return Collection<int, array{business: Business, gross_sales_30d: float}>
     */
    public function businessesWithGrossSales30d(?Carbon $windowStart = null): Collection
    {
        $windowStart ??= now()->subDays($this->activityWindowDays());

        $grossByBusiness = Sale::query()
            ->where('sale_date', '>=', $windowStart)
            ->groupBy('business_id')
            ->selectRaw('business_id')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as gross_sales_30d')
            ->pluck('gross_sales_30d', 'business_id');

        return Business::query()
            ->with(['owner:id,name,email', 'subscription.plan:id,name'])
            ->withCount('users as staff_count')
            ->get()
            ->map(function (Business $business) use ($grossByBusiness, $windowStart) {
                $business->gross_sales_30d = (float) ($grossByBusiness[$business->id] ?? 0);

                return [
                    'business' => $business,
                    'gross_sales_30d' => $business->gross_sales_30d,
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

        $lastSaleAt = $business->last_sale_at ? Carbon::parse($business->last_sale_at) : null;
        $lastLoginAt = $business->last_user_login_at ? Carbon::parse($business->last_user_login_at) : null;
        $lastActivityAt = collect([$lastSaleAt, $lastLoginAt])->filter()->max();

        $gross30d = (float) ($business->getAttributes()['gross_sales_30d'] ?? $business->gross_sales_30d ?? 0);

        $hasSalesEver = $lastSaleAt !== null || Sale::where('business_id', $business->id)->exists();
        $hasRecentSale = $gross30d > 0 || ($lastSaleAt && $lastSaleAt->gte($windowStart));
        $hasRecentLogin = $lastLoginAt && $lastLoginAt->gte($windowStart);

        $activityStatus = 'dormant';
        if ($business->status === 'suspended') {
            $activityStatus = 'suspended';
        } elseif (! $hasSalesEver && ! $lastLoginAt) {
            $activityStatus = 'never_used';
        } elseif ($hasRecentSale || $hasRecentLogin) {
            $activityStatus = 'active';
        }

        return [
            'id' => $business->id,
            'name' => $business->name,
            'slug' => $business->slug,
            'email' => $business->email,
            'currency' => $business->currency,
            'status' => $business->status,
            'activity_status' => $activityStatus,
            'owner_name' => $business->owner?->name,
            'owner_email' => $business->owner?->email,
            'plan_name' => $business->subscription?->plan?->name,
            'subscription_status' => $business->subscription?->status,
            'trial_ends_at' => $business->trial_ends_at?->toIso8601String(),
            'staff_count' => (int) ($business->staff_count ?? 0),
            'gross_sales_today' => $this->formatGross((float) ($business->getAttributes()['gross_sales_today'] ?? 0)),
            'gross_sales_7d' => $this->formatGross((float) ($business->getAttributes()['gross_sales_7d'] ?? 0)),
            'gross_sales_30d' => $this->formatGross($gross30d),
            'gross_sales_all_time' => $this->formatGross((float) ($business->getAttributes()['gross_sales_all_time'] ?? 0)),
            'transactions_30d' => (int) ($business->transactions_30d ?? 0),
            'last_activity_at' => $lastActivityAt?->toIso8601String(),
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
        $activeStatusCount = Business::where('status', 'active')->count();

        $withGrossSales30d = (int) Sale::query()
            ->where('sale_date', '>=', $windowStart)
            ->distinct('business_id')
            ->count('business_id');

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

    public function updateStatus(User $actor, Business $business, string $status, string $reason): Business
    {
        $business->update(['status' => $status]);

        $this->audit->log($actor, $status === 'suspended' ? 'business.suspended' : 'business.reactivated', 'business', $business->id, $reason);

        $this->notifications->notifyBusinessStatusChange(
            $business->name,
            $business->owner?->email,
            $business->email,
            $status,
            $reason,
        );

        return $business->fresh(['owner', 'subscription.plan']);
    }

    private function grossSalesSubquery(?Carbon $since = null): \Closure
    {
        return function ($sub) use ($since) {
            $sub->from('sales')
                ->selectRaw('COALESCE(SUM(total_amount), 0)')
                ->whereColumn('sales.business_id', 'businesses.id');

            if ($since) {
                $sub->where('sale_date', '>=', $since);
            }
        };
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
            return 1;
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
