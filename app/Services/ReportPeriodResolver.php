<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Support\ReportPeriodContext;
use Illuminate\Http\Request;

class ReportPeriodResolver
{
    public function __construct(
        protected AccountingPeriodService $accountingPeriodService,
    ) {}

    public function resolve(int $businessId, Request $request): ReportPeriodContext
    {
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        if ($dateFrom && $dateTo) {
            return $this->resolveFromDateRange($businessId, (string) $dateFrom, (string) $dateTo);
        }

        $periodIdParam = $request->query('period_id');
        if ($periodIdParam) {
            $ids = $this->parsePeriodIds((string) $periodIdParam);
            if (count($ids) > 1) {
                return $this->resolveFromPeriodIds($businessId, $ids);
            }
            if (count($ids) === 1) {
                return $this->resolveSinglePeriod($businessId, $ids[0]);
            }
        }

        $current = $this->accountingPeriodService->getCurrentPeriod($businessId);

        return $this->resolveSinglePeriod($businessId, (int) $current->id);
    }

    /**
     * @return int[]
     */
    public function parsePeriodIds(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $value)))));
    }

    /**
     * @param  int[]  $periodIds
     */
    public function resolveFromPeriodIds(int $businessId, array $periodIds): ReportPeriodContext
    {
        $periods = AccountingPeriod::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $periodIds)
            ->orderBy('start_date')
            ->get();

        if ($periods->isEmpty()) {
            throw new \RuntimeException('No accounting periods found for the requested range.');
        }

        return $this->buildContext($periods);
    }

    public function resolveFromDateRange(int $businessId, string $dateFrom, string $dateTo): ReportPeriodContext
    {
        if ($dateFrom > $dateTo) {
            throw new \InvalidArgumentException('date_from must be on or before date_to.');
        }

        $periods = AccountingPeriod::query()
            ->where('business_id', $businessId)
            ->where('start_date', '<=', $dateTo)
            ->where('end_date', '>=', $dateFrom)
            ->orderBy('start_date')
            ->get();

        if ($periods->isEmpty()) {
            $this->accountingPeriodService->ensurePeriodsForBusiness($businessId);
            $periods = AccountingPeriod::query()
                ->where('business_id', $businessId)
                ->where('start_date', '<=', $dateTo)
                ->where('end_date', '>=', $dateFrom)
                ->orderBy('start_date')
                ->get();
        }

        if ($periods->isEmpty()) {
            throw new \RuntimeException('No accounting periods found for the requested date range.');
        }

        return $this->buildContext($periods);
    }

    protected function resolveSinglePeriod(int $businessId, int $periodId): ReportPeriodContext
    {
        $period = AccountingPeriod::query()
            ->where('business_id', $businessId)
            ->whereKey($periodId)
            ->firstOrFail();

        return $this->buildContext(collect([$period]));
    }

    protected function buildContext(\Illuminate\Support\Collection $periods): ReportPeriodContext
    {
        /** @var AccountingPeriod $first */
        $first = $periods->first();
        /** @var AccountingPeriod $last */
        $last = $periods->last();

        $periodIds = $periods->pluck('id')->map(fn ($id) => (int) $id)->all();

        $prior = AccountingPeriod::query()
            ->where('business_id', $first->business_id)
            ->where('end_date', '<', $first->start_date)
            ->orderByDesc('end_date')
            ->first();

        $label = $periods->count() === 1
            ? $first->name
            : $first->start_date->format('M Y').' – '.$last->end_date->format('M Y');

        return new ReportPeriodContext(
            periodIds: $periodIds,
            snapshotPeriodId: (int) $last->id,
            priorSnapshotPeriodId: $prior?->id ? (int) $prior->id : null,
            dateFrom: $first->start_date->toDateString(),
            dateTo: $last->end_date->toDateString(),
            label: $label,
            isRange: $periods->count() > 1,
        );
    }
}
