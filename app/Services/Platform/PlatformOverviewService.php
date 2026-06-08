<?php

namespace App\Services\Platform;

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
        $businessRows = $this->businessService->businessesWithGrossSales30d($windowStart);

        $activityCounts = [
            'active' => 0,
            'dormant' => 0,
            'never_used' => 0,
            'suspended' => 0,
        ];

        foreach ($businessRows as $entry) {
            $activityCounts[$entry['row']['activity_status']]++;
        }

        $withGrossSales = $businessRows->filter(fn (array $e) => $e['gross_sales_30d'] > 0)->count();
        $totalBusinesses = $businessRows->count();

        $topBusinesses = $businessRows
            ->sortByDesc('gross_sales_30d')
            ->take(10)
            ->map(fn (array $e) => $e['row'])
            ->values()
            ->all();

        $grossIncomeDistribution = $this->businessService->grossIncomeDistribution($windowStart);

        return [
            'businesses' => [
                'total' => $totalBusinesses,
                'active' => $activityCounts['active'],
                'dormant' => $activityCounts['dormant'],
                'never_used' => $activityCounts['never_used'],
                'suspended' => $activityCounts['suspended'],
                'with_gross_sales_30d' => $withGrossSales,
            ],
            'users' => [
                'total' => User::count(),
                'active' => User::where('is_active', true)->count(),
                'deactivated' => User::where('is_active', false)->count(),
            ],
            'system' => [
                'api_status' => 'healthy',
                'database_latency_ms' => $this->databaseLatencyMs(),
                'queue_pending' => $this->queuePendingCount(),
                'version' => config('app.version', '1.0.0'),
            ],
            'pricing_insights' => [
                'activity_window_days' => $this->businessService->activityWindowDays(),
                'businesses_with_gross_sales_30d' => $withGrossSales,
                'businesses_without_gross_sales_30d' => $totalBusinesses - $withGrossSales,
                'gross_income_distribution' => $grossIncomeDistribution,
            ],
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

            $signups = \App\Models\Business::whereDate('created_at', $date)->count();
            $transactions = Sale::whereDate('sale_date', $date)->count();
            $activeBusinesses = Sale::whereDate('sale_date', $date)
                ->distinct('business_id')
                ->count('business_id');
            $grossSales = (float) Sale::whereDate('sale_date', $date)->sum('total_amount');

            $trend[] = [
                'date' => $date,
                'signups' => $signups,
                'transactions' => $transactions,
                'active_businesses' => $activeBusinesses,
                'gross_sales' => number_format($grossSales, 2, '.', ''),
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
            ->limit(8)
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
