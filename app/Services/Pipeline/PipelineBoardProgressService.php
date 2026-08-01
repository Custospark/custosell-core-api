<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardMetricSnapshot;
use App\Models\PipelineBoardProgressConfig;
use App\Models\PipelineBoardTarget;
use App\Models\User;
use App\Services\PipelineService;
use Carbon\Carbon;

class PipelineBoardProgressService
{
    public function __construct(
        protected PipelineService $pipeline,
        protected PipelineColumnMetricsService $columnMetrics,
        protected PipelineGoalDecompositionService $decomposition,
        protected PipelineBoardProgressPeriodService $period,
        protected PipelineBoardProgressMetricsService $metrics,
        protected PipelineBoardTargetService $targets,
        protected PipelineBoardTargetProgressService $targetProgress,
    ) {}

    /** @return array<string, mixed> */
    public function progressSummary(
        int $businessId,
        User $user,
        int $boardId,
        string $periodType = 'month',
        ?string $from = null,
        ?string $to = null,
        ?array $stageIds = null,
    ): array {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);
        [$start, $end] = $this->period->resolvePeriod($periodType, $from, $to);
        $context = $this->metrics->boardContext($board);
        $resolvedStageIds = $this->targets->resolveStageIds($board, $stageIds);

        $teamMetrics = $this->metrics->computeTeamMetrics($board, $start, $end);
        $memberMetrics = $this->metrics->computeMemberMetrics($board, $start, $end);
        $trends = $this->metrics->computeTrendSeries($board, $start, $end);
        $expectedTrend = $this->metrics->computeExpectedTrendSeries($board, $start, $end, $resolvedStageIds);
        $funnel = $this->metrics->computeStageFunnel($board, $start, $end);
        $columnMetrics = $this->columnMetrics->columnMetricsForStages($board, $resolvedStageIds, $start, $end);
        $columnTrends = $this->columnMetrics->columnTrendSeries($board, $resolvedStageIds, $start, $end);
        $targets = $this->targetProgress->listTargetsWithProgress($businessId, $user, $boardId, $start, $end, $periodType);
        $stages = $this->columnMetrics->serializeBoardStages($board);
        $config = $this->getProgressConfig($businessId, $user, $boardId);
        $alerts = $this->targetProgress->computePaceAlerts($targets);
        $capacityRecommendations = $this->columnMetrics->capacityRecommendations($board, $resolvedStageIds);

        return [
            'board_id' => $board->id,
            'period' => [
                'type' => $periodType,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'context' => $context,
            'stages' => $stages,
            'selected_stage_ids' => $resolvedStageIds,
            'team' => $teamMetrics,
            'members' => $memberMetrics,
            'trends' => $trends,
            'expected_trends' => $expectedTrend,
            'funnel' => $funnel,
            'column_metrics' => $columnMetrics,
            'column_trends' => $columnTrends,
            'targets' => $targets,
            'chart_config' => $config,
            'pace_alerts' => $alerts,
            'capacity_recommendations' => $capacityRecommendations,
            'can_manage_targets' => $this->pipeline->userCanManageBoard($user, $board),
        ];
    }

    /** @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function progressQuery(int $businessId, User $user, int $boardId, array $filters): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);
        $periodType = $filters['period'] ?? 'month';
        [$start, $end] = $this->period->resolvePeriod($periodType, $filters['from'] ?? null, $filters['to'] ?? null);
        $stageIds = $this->targets->resolveStageIds($board, $filters['stage_ids'] ?? null);
        $memberIds = array_map('intval', $filters['member_ids'] ?? []);
        $metrics = $filters['metrics'] ?? PipelineBoardProgressMetricsService::METRIC_KEYS;

        $series = [];
        foreach ($metrics as $metricKey) {
            if ($parsed = $this->columnMetrics->parseStageMetricKey($metricKey)) {
                $value = $this->columnMetrics->computeStageMetric(
                    $board,
                    $parsed['stage_id'],
                    $parsed['suffix'],
                    $start,
                    $end,
                    $memberIds[0] ?? null,
                );
                $series[] = ['metric_key' => $metricKey, 'value' => $value];
                continue;
            }
            if (in_array($metricKey, PipelineBoardProgressMetricsService::METRIC_KEYS, true)) {
                $series[] = [
                    'metric_key' => $metricKey,
                    'value' => $this->metrics->computeMetricValue($board, $metricKey, $start, $end, $memberIds[0] ?? null),
                ];
            }
        }

        return [
            'board_id' => $board->id,
            'period' => ['type' => $periodType, 'start' => $start->toDateString(), 'end' => $end->toDateString()],
            'stage_ids' => $stageIds,
            'member_ids' => $memberIds,
            'series' => $series,
            'column_metrics' => $this->columnMetrics->columnMetricsForStages($board, $stageIds, $start, $end),
            'column_trends' => $this->columnMetrics->columnTrendSeries($board, $stageIds, $start, $end),
        ];
    }

    public function myProgress(int $businessId, User $user, int $boardId, string $periodType = 'month'): array
    {
        $summary = $this->progressSummary($businessId, $user, $boardId, $periodType);
        $myTargets = collect($summary['targets'])->filter(function ($target) use ($user) {
            return ($target['scope'] ?? 'board') === 'member'
                && (int) ($target['member_user_id'] ?? 0) === (int) $user->id;
        })->values()->all();

        $myMember = collect($summary['members'])->firstWhere('user_id', (int) $user->id);
        $teamAvgWon = collect($summary['members'])->avg(fn ($m) => $m['metrics']['cards_won'] ?? 0) ?? 0;

        return [
            'user_id' => (int) $user->id,
            'period' => $summary['period'],
            'context' => $summary['context'],
            'metrics' => $myMember['metrics'] ?? [],
            'targets' => $myTargets,
            'team_average' => ['cards_won' => round($teamAvgWon, 1)],
            'pace_alerts' => $this->targetProgress->computePaceAlerts($myTargets),
            'selected_stage_ids' => $summary['selected_stage_ids'],
            'column_metrics' => $summary['column_metrics'],
        ];
    }

    /** @return array<string, mixed> */
    public function getProgressConfig(int $businessId, User $user, int $boardId): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);
        $row = PipelineBoardProgressConfig::query()
            ->where('board_id', $board->id)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhereNull('user_id');
            })
            ->orderByRaw('user_id is null')
            ->first();

        return $row?->config_json ?? $this->targets->defaultChartConfig($board);
    }

    /** @param  array<string, mixed>  $config */
    public function saveProgressConfig(int $businessId, User $user, int $boardId, array $config): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);
        $this->pipeline->ensureCanManageBoard($user, $board);

        $row = PipelineBoardProgressConfig::query()->updateOrCreate(
            ['board_id' => $board->id, 'user_id' => $user->id],
            ['business_id' => $businessId, 'config_json' => $config],
        );

        return $row->config_json ?? [];
    }

    /** @param  array<string, mixed>  $data */
    public function decomposePreview(int $businessId, User $user, int $boardId, array $data): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);

        return $this->decomposition->preview($businessId, $board, $data);
    }

    /** @return list<array<string, mixed>> */
    public function listTargets(int $businessId, User $user, int $boardId): array
    {
        return $this->targets->listTargets($businessId, $user, $boardId);
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function storeTarget(int $businessId, User $user, int $boardId, array $data): array
    {
        return $this->targets->storeTarget($businessId, $user, $boardId, $data);
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateTarget(int $businessId, User $user, int $targetId, array $data): array
    {
        return $this->targets->updateTarget($businessId, $user, $targetId, $data);
    }

    public function archiveTarget(int $businessId, User $user, int $targetId): void
    {
        $this->targets->archiveTarget($businessId, $user, $targetId);
    }

    public function recordDailySnapshots(int $businessId, int $boardId, ?Carbon $date = null): void
    {
        $board = PipelineBoard::query()
            ->where('business_id', $businessId)
            ->whereKey($boardId)
            ->firstOrFail();

        $snapshotDate = ($date ?? now())->copy()->startOfDay();
        $start = $snapshotDate->copy()->startOfDay();
        $end = $snapshotDate->copy()->endOfDay();

        foreach (PipelineBoardProgressMetricsService::METRIC_KEYS as $metricKey) {
            if (in_array($metricKey, ['win_rate', 'conversion_rate', 'avg_cycle_days'], true)) {
                continue;
            }

            $teamValue = $this->metrics->computeMetricValue($board, $metricKey, $start, $end, null);
            PipelineBoardMetricSnapshot::query()->updateOrCreate(
                [
                    'board_id' => $board->id,
                    'snapshot_date' => $snapshotDate->toDateString(),
                    'metric_key' => $metricKey,
                    'scope' => 'board',
                    'member_user_id' => null,
                ],
                [
                    'business_id' => $businessId,
                    'actual_value' => $teamValue,
                ],
            );
        }
    }

    /**
     * Public snapshot of a target for HR performance evaluation (member goals).
     *
     * @return array<string, mixed>
     */
    public function serializeTargetForHr(
        PipelineBoardTarget $target,
        PipelineBoard $board,
        ?Carbon $start = null,
        ?Carbon $end = null,
        ?string $viewPeriodType = null,
    ): array {
        return $this->targetProgress->serializeTargetForHr($target, $board, $start, $end, $viewPeriodType);
    }

    /**
     * @return array{Carbon, Carbon}
     */
    public function resolvePeriod(string $periodType, ?string $from, ?string $to): array
    {
        return $this->period->resolvePeriod($periodType, $from, $to);
    }
}
