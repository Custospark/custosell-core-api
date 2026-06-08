<?php

namespace App\Services\Platform;

use App\Models\Business;
use App\Models\PlatformAuditLog;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PlatformOverviewService
{
    public function __construct(
        protected PlatformBusinessService $businessService,
    ) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $windowStart = now()->subDays($this->businessService->activityWindowDays());
        $businesses = Business::query()->with(['subscription.plan'])->get();

        $activityCounts = [
            'active' => 0,
            'dormant' => 0,
            'never_used' => 0,
            'suspended' => 0,
        ];

        foreach ($businesses as $business) {
            $row = $this->businessService->transformBusiness($business, $windowStart);
            $activityCounts[$row['activity_status']]++;
        }

        $usersTotal = User::count();
        $usersActive = User::where('is_active', true)->count();

        $revenueByCurrency = Sale::query()
            ->join('businesses', 'businesses.id', '=', 'sales.business_id')
            ->where('sales.sale_date', '>=', $windowStart)
            ->groupBy('businesses.currency')
            ->selectRaw('businesses.currency as currency')
            ->selectRaw('COALESCE(SUM(sales.total_amount), 0) as revenue_30d_gross')
            ->selectRaw('COUNT(DISTINCT sales.business_id) as business_count')
            ->get()
            ->map(function ($row) use ($windowStart) {
                $refunds = (float) DB::table('sale_items')
                    ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                    ->join('businesses', 'businesses.id', '=', 'sales.business_id')
                    ->where('businesses.currency', $row->currency)
                    ->where('sales.sale_date', '>=', $windowStart)
                    ->sum('sale_items.refunded_amount');

                return [
                    'currency' => $row->currency,
                    'revenue_30d' => number_format(max(0, (float) $row->revenue_30d_gross - $refunds), 2, '.', ''),
                    'business_count' => (int) $row->business_count,
                ];
            })
            ->values()
            ->all();

        $topBusinesses = Business::query()
            ->with('owner:id,name,email')
            ->get()
            ->map(fn (Business $b) => $this->businessService->transformBusiness($b, $windowStart))
            ->sortByDesc(fn (array $row) => (float) $row['revenue_30d'])
            ->take(10)
            ->values()
            ->all();

        return [
            'businesses' => [
                'total' => $businesses->count(),
                'active' => $activityCounts['active'],
                'dormant' => $activityCounts['dormant'],
                'never_used' => $activityCounts['never_used'],
                'suspended' => $activityCounts['suspended'],
            ],
            'users' => [
                'total' => $usersTotal,
                'active' => $usersActive,
                'deactivated' => $usersTotal - $usersActive,
            ],
            'system' => [
                'api_status' => 'healthy',
                'database_latency_ms' => $this->databaseLatencyMs(),
                'queue_pending' => $this->queuePendingCount(),
                'version' => config('app.version', '1.0.0'),
            ],
            'revenue_by_currency' => $revenueByCurrency,
            'top_businesses_30d' => $topBusinesses,
            'recent_events' => $this->recentEvents(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function metrics(int $days = 7): array
    {
        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $dayStart = Carbon::parse($date)->startOfDay();
            $dayEnd = Carbon::parse($date)->endOfDay();

            $signups = Business::whereBetween('created_at', [$dayStart, $dayEnd])->count();
            $transactions = Sale::whereDate('sale_date', $date)->count();
            $activeBusinesses = Sale::whereDate('sale_date', $date)
                ->distinct('business_id')
                ->count('business_id');

            $trend[] = [
                'date' => $date,
                'signups' => $signups,
                'transactions' => $transactions,
                'active_businesses' => $activeBusinesses,
            ];
        }

        return $trend;
    }

    private function databaseLatencyMs(): int
    {
        $start = microtime(true);
        DB::select('SELECT 1');

        return (int) round((microtime(true) - $start) * 1000);
    }

    private function queuePendingCount(): int
    {
        if (! DB::getSchemaBuilder()->hasTable('jobs')) {
            return 0;
        }

        return (int) DB::table('jobs')->count();
    }

    /** @return list<array<string, mixed>> */
    private function recentEvents(): array
    {
        return PlatformAuditLog::query()
            ->with('actor:id,name')
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (PlatformAuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'reason' => $log->reason,
                'actor_name' => $log->actor?->name,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
