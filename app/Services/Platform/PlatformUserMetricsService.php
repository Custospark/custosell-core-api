<?php

namespace App\Services\Platform;

use App\Models\User;
use Carbon\Carbon;

/**
 * Platform-wide user analytics (onboarding dashboard). All counts are computed
 * from the database, never from a paginated slice, so the summary cards show
 * true totals regardless of the list's page size.
 */
class PlatformUserMetricsService
{
    protected const ACTIVE_LOGIN_DAYS = 30;

    /**
     * Onboarding and growth stats for the platform users dashboard.
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

        $joinedToday = User::where('created_at', '>=', $todayStart)->count();
        $joinedThisWeek = User::where('created_at', '>=', $weekStart)->count();
        $joinedThisMonth = User::where('created_at', '>=', $monthStart)->count();
        $joinedInRange = User::whereBetween('created_at', [$rangeFrom, $rangeTo])->count();

        $totalUsers = User::count();
        $activeCount = User::where('status', 'active')->count();
        $warningCount = User::where('status', 'warning')->count();
        $notifiedCount = User::where('status', 'notified')->count();
        $restrictedCount = User::where('status', 'restricted')->count();
        $deactivatedCount = User::where('status', 'deactivated')->count();
        $withBusinessCount = User::whereNotNull('business_id')->count();
        $platformAdminCount = User::whereHas('roles', fn ($q) => $q->where('name', 'platform-admin'))->count();
        $logins30d = User::where('last_login_at', '>=', now()->subDays(self::ACTIVE_LOGIN_DAYS))->count();

        $cumulativeBeforeRange = User::where('created_at', '<', $rangeFrom)->count();
        $growth = [];
        $cursor = $rangeFrom->copy()->startOfDay();
        $end = $rangeTo->copy()->startOfDay();
        $runningTotal = $cumulativeBeforeRange;

        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $signups = User::whereDate('created_at', $date)->count();
            $runningTotal += $signups;
            $growth[] = [
                'date' => $date,
                'signups' => $signups,
                'cumulative' => $runningTotal,
            ];
            $cursor->addDay();
        }

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
                'total' => $totalUsers,
                'active' => $activeCount,
                'warning' => $warningCount,
                'notified' => $notifiedCount,
                'restricted' => $restrictedCount,
                'deactivated' => $deactivatedCount,
                'with_business' => $withBusinessCount,
                'platform_admins' => $platformAdminCount,
                'logins_30d' => $logins30d,
            ],
            'growth' => $growth,
            'decisions' => [
                $joinedToday > 0
                    ? "{$joinedToday} new user(s) joined today — prioritize welcome onboarding."
                    : 'No new signups today — focus on re-activating dormant accounts.',
                $joinedThisMonth > 0
                    ? "{$joinedThisMonth} user(s) joined this month."
                    : 'No new users this month — review acquisition channels.',
                $logins30d > 0
                    ? "{$logins30d} of {$totalUsers} users signed in within the last 30 days."
                    : 'No user sign-ins recorded in the last 30 days.',
                $deactivatedCount > 0
                    ? "{$deactivatedCount} deactivated account(s) platform-wide."
                    : 'No deactivated accounts platform-wide.',
            ],
        ];
    }
}