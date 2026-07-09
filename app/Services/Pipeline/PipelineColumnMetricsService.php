<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineLead;
use App\Models\PipelineLeadActivity;
use App\Models\PipelineLeadAssignee;
use App\Models\PipelineStage;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class PipelineColumnMetricsService
{
    public const COLUMN_METRIC_SUFFIXES = [
        'count',
        'open_value',
        'entries',
        'exits',
        'throughput',
        'avg_dwell_days',
        'overdue',
    ];

    public function metricKeyForStage(int $stageId, string $suffix): string
    {
        return "stage:{$stageId}:{$suffix}";
    }

    public function parseStageMetricKey(string $metricKey): ?array
    {
        if (! preg_match('/^stage:(\d+):([a-z_]+)$/', $metricKey, $matches)) {
            return null;
        }

        return [
            'stage_id' => (int) $matches[1],
            'suffix' => $matches[2],
        ];
    }

    /** @param  list<int>  $stageIds */
    public function columnMetricsForStages(
        PipelineBoard $board,
        array $stageIds,
        Carbon $start,
        Carbon $end,
        ?int $memberUserId = null,
    ): array {
        $board->loadMissing('stages');
        $validIds = $board->stages->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_intersect($stageIds, $validIds));
        if ($ids === []) {
            $ids = $validIds;
        }

        $rows = [];
        foreach ($ids as $stageId) {
            $stage = $board->stages->firstWhere('id', $stageId);
            if (! $stage) {
                continue;
            }
            $metrics = [];
            foreach (self::COLUMN_METRIC_SUFFIXES as $suffix) {
                $metrics[$suffix] = $this->computeStageMetric($board, $stageId, $suffix, $start, $end, $memberUserId);
            }
            $rows[] = [
                'stage_id' => $stageId,
                'stage_name' => $stage->name,
                'color' => $stage->color,
                'is_won' => (bool) $stage->is_won,
                'is_lost' => (bool) $stage->is_lost,
                'metrics' => $metrics,
            ];
        }

        return $rows;
    }

    /** @param  list<int>  $stageIds
     * @return list<array<string, mixed>>
     */
    public function capacityRecommendations(
        PipelineBoard $board,
        array $stageIds,
        int $lookbackDays = 90,
    ): array {
        $end = now()->endOfDay();
        $start = now()->copy()->subDays($lookbackDays)->startOfDay();
        $board->loadMissing('stages');
        $validIds = array_values(array_intersect(
            $stageIds,
            $board->stages->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ));
        if ($validIds === []) {
            $validIds = $board->stages->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $recommendations = [];
        foreach ($validIds as $stageId) {
            $stage = $board->stages->firstWhere('id', $stageId);
            if (! $stage) {
                continue;
            }
            $throughput = $this->computeStageMetric($board, $stageId, 'throughput', $start, $end, null);
            $dwell = $this->computeStageMetric($board, $stageId, 'avg_dwell_days', $start, $end, null);
            $wip = $this->computeStageMetric($board, $stageId, 'count', $start, $end, null);
            $weeklyCapacity = max(1, (int) round($throughput / max(1, $lookbackDays / 7)));

            if ($dwell >= 14 && $wip >= 5) {
                $recommendations[] = [
                    'stage_id' => $stageId,
                    'stage_name' => $stage->name,
                    'type' => 'bottleneck',
                    'message' => sprintf(
                        'High dwell (%.0f days) with %d items in column — consider redistributing work or adding capacity.',
                        $dwell,
                        (int) $wip,
                    ),
                    'suggested_weekly_capacity' => $weeklyCapacity,
                    'avg_dwell_days' => round($dwell, 1),
                    'throughput_90d' => (int) $throughput,
                ];
            } elseif ($throughput > 0 && $weeklyCapacity > 0) {
                $recommendations[] = [
                    'stage_id' => $stageId,
                    'stage_name' => $stage->name,
                    'type' => 'healthy',
                    'message' => sprintf(
                        'Column processed %d moves in the last %d days — sustainable weekly pace is about %d.',
                        (int) $throughput,
                        $lookbackDays,
                        $weeklyCapacity,
                    ),
                    'suggested_weekly_capacity' => $weeklyCapacity,
                    'avg_dwell_days' => round($dwell, 1),
                    'throughput_90d' => (int) $throughput,
                ];
            }
        }

        return $recommendations;
    }

    public function computeStageMetric(
        PipelineBoard $board,
        int $stageId,
        string $suffix,
        Carbon $start,
        Carbon $end,
        ?int $memberUserId = null,
    ): float {
        $leadQuery = $this->scopedLeadQuery($board, $memberUserId);

        return match ($suffix) {
            'count' => (float) (clone $leadQuery)
                ->where('stage_id', $stageId)
                ->whereIn('status', ['open', 'won', 'lost', 'converted'])
                ->count(),
            'open_value' => round((float) (clone $leadQuery)
                ->where('stage_id', $stageId)
                ->where('status', 'open')
                ->sum('estimated_value'), 2),
            'entries' => (float) PipelineLeadActivity::query()
                ->where('business_id', $board->business_id)
                ->where('type', 'stage_change')
                ->whereBetween('created_at', [$start, $end])
                ->whereIn('lead_id', (clone $leadQuery)->select('id'))
                ->where('metadata->to_stage_id', $stageId)
                ->count(),
            'exits' => (float) PipelineLeadActivity::query()
                ->where('business_id', $board->business_id)
                ->where('type', 'stage_change')
                ->whereBetween('created_at', [$start, $end])
                ->whereIn('lead_id', (clone $leadQuery)->select('id'))
                ->where('metadata->from_stage_id', $stageId)
                ->count(),
            'throughput' => (float) PipelineLeadActivity::query()
                ->where('business_id', $board->business_id)
                ->where('type', 'stage_change')
                ->whereBetween('created_at', [$start, $end])
                ->whereIn('lead_id', (clone $leadQuery)->select('id'))
                ->where('metadata->to_stage_id', $stageId)
                ->count(),
            'avg_dwell_days' => $this->avgDwellDaysInStage($board, $stageId, $start, $end, $memberUserId),
            'overdue' => (float) (clone $leadQuery)
                ->where('stage_id', $stageId)
                ->where('status', 'open')
                ->whereNotNull('expected_close_date')
                ->whereDate('expected_close_date', '<', now()->toDateString())
                ->count(),
            default => 0.0,
        };
    }

    /** @param  list<int>  $stageIds
     * @return list<array{date: string, stages: array<int, array<string, float>>}>
     */
    public function columnTrendSeries(
        PipelineBoard $board,
        array $stageIds,
        Carbon $start,
        Carbon $end,
        ?int $memberUserId = null,
    ): array {
        $period = CarbonPeriod::create($start->copy()->startOfDay(), '1 day', $end->copy()->startOfDay());
        $series = [];

        foreach ($period as $day) {
            $dayStart = $day->copy()->startOfDay();
            $dayEnd = $day->copy()->endOfDay();
            $stages = [];
            foreach ($stageIds as $stageId) {
                $stages[$stageId] = [
                    'throughput' => $this->computeStageMetric($board, $stageId, 'throughput', $dayStart, $dayEnd, $memberUserId),
                    'count' => $this->computeStageMetric($board, $stageId, 'count', $dayStart, $dayEnd, $memberUserId),
                ];
            }
            $series[] = [
                'date' => $day->toDateString(),
                'stages' => $stages,
            ];
        }

        return $series;
    }

    /** @param  list<int>  $stageIds
     * @return array<int, float>
     */
    public function columnThroughputWeights(PipelineBoard $board, array $stageIds, int $lookbackDays = 90): array
    {
        $end = now()->endOfDay();
        $start = now()->copy()->subDays($lookbackDays)->startOfDay();
        $weights = [];
        $total = 0.0;

        foreach ($stageIds as $stageId) {
            $value = max(0, $this->computeStageMetric($board, $stageId, 'throughput', $start, $end, null));
            $weights[$stageId] = $value;
            $total += $value;
        }

        if ($total <= 0) {
            $equal = count($stageIds) > 0 ? 1 / count($stageIds) : 1;
            foreach ($stageIds as $stageId) {
                $weights[$stageId] = $equal;
            }

            return $weights;
        }

        foreach ($stageIds as $stageId) {
            $weights[$stageId] = $weights[$stageId] / $total;
        }

        return $weights;
    }

    /** @return list<array<string, mixed>> */
    public function serializeBoardStages(PipelineBoard $board): array
    {
        $board->loadMissing('stages');

        return $board->stages->map(fn (PipelineStage $stage) => [
            'stage_id' => (int) $stage->id,
            'stage_name' => $stage->name,
            'color' => $stage->color,
            'sort_order' => (int) $stage->sort_order,
            'is_won' => (bool) $stage->is_won,
            'is_lost' => (bool) $stage->is_lost,
        ])->values()->all();
    }

    protected function scopedLeadQuery(PipelineBoard $board, ?int $memberUserId)
    {
        $query = PipelineLead::query()->where('board_id', $board->id);

        if ($memberUserId) {
            $query->where(function ($q) use ($memberUserId) {
                $q->where('assigned_to', $memberUserId)
                    ->orWhereIn('id', PipelineLeadAssignee::query()->where('user_id', $memberUserId)->select('lead_id'));
            });
        }

        return $query;
    }

    protected function avgDwellDaysInStage(
        PipelineBoard $board,
        int $stageId,
        Carbon $start,
        Carbon $end,
        ?int $memberUserId,
    ): float {
        $activities = PipelineLeadActivity::query()
            ->where('business_id', $board->business_id)
            ->where('type', 'stage_change')
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('lead_id', $this->scopedLeadQuery($board, $memberUserId)->select('id'))
            ->where(function ($q) use ($stageId) {
                $q->where('metadata->to_stage_id', $stageId)
                    ->orWhere('metadata->from_stage_id', $stageId);
            })
            ->orderBy('created_at')
            ->get(['lead_id', 'created_at', 'metadata']);

        if ($activities->isEmpty()) {
            return 0.0;
        }

        $byLead = $activities->groupBy('lead_id');
        $totalDays = 0.0;
        $samples = 0;

        foreach ($byLead as $leadActivities) {
            $enteredAt = null;
            foreach ($leadActivities as $activity) {
                $toStage = (int) ($activity->metadata['to_stage_id'] ?? 0);
                $fromStage = (int) ($activity->metadata['from_stage_id'] ?? 0);
                if ($toStage === $stageId) {
                    $enteredAt = $activity->created_at;
                } elseif ($fromStage === $stageId && $enteredAt) {
                    $totalDays += $enteredAt->diffInDays($activity->created_at);
                    $samples++;
                    $enteredAt = null;
                }
            }
        }

        return $samples > 0 ? round($totalDays / $samples, 1) : 0.0;
    }
}
