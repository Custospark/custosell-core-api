<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardMetricSnapshot;
use App\Models\PipelineBoardProgressConfig;
use App\Models\PipelineBoardTarget;
use App\Models\PipelineBoardTargetAllocation;
use App\Models\PipelineChecklistItem;
use App\Models\PipelineLead;
use App\Models\PipelineLeadActivity;
use App\Models\PipelineLeadAssignee;
use App\Models\User;
use App\Services\PipelineService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PipelineBoardProgressService
{
    public const METRIC_KEYS = [
        'cards_created',
        'cards_won',
        'cards_lost',
        'cards_converted',
        'cards_open',
        'pipeline_value_open',
        'pipeline_value_won',
        'win_rate',
        'conversion_rate',
        'avg_cycle_days',
        'cards_moved',
        'comments_posted',
        'checklist_items_done',
        'overdue_cards',
    ];

    public function __construct(
        protected PipelineService $pipeline,
        protected PipelineColumnMetricsService $columnMetrics,
        protected PipelineGoalDecompositionService $decomposition,
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
        [$start, $end] = $this->resolvePeriod($periodType, $from, $to);
        $context = $this->boardContext($board);
        $resolvedStageIds = $this->resolveStageIds($board, $stageIds);

        $teamMetrics = $this->computeTeamMetrics($board, $start, $end);
        $memberMetrics = $this->computeMemberMetrics($board, $start, $end);
        $trends = $this->computeTrendSeries($board, $start, $end);
        $expectedTrend = $this->computeExpectedTrendSeries($board, $start, $end, $resolvedStageIds);
        $funnel = $this->computeStageFunnel($board, $start, $end);
        $columnMetrics = $this->columnMetrics->columnMetricsForStages($board, $resolvedStageIds, $start, $end);
        $columnTrends = $this->columnMetrics->columnTrendSeries($board, $resolvedStageIds, $start, $end);
        $targets = $this->listTargetsWithProgress($businessId, $user, $boardId, $start, $end, $periodType);
        $stages = $this->columnMetrics->serializeBoardStages($board);
        $config = $this->getProgressConfig($businessId, $user, $boardId);
        $alerts = $this->computePaceAlerts($targets);
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
        [$start, $end] = $this->resolvePeriod($periodType, $filters['from'] ?? null, $filters['to'] ?? null);
        $stageIds = $this->resolveStageIds($board, $filters['stage_ids'] ?? null);
        $memberIds = array_map('intval', $filters['member_ids'] ?? []);
        $metrics = $filters['metrics'] ?? self::METRIC_KEYS;

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
            if (in_array($metricKey, self::METRIC_KEYS, true)) {
                $series[] = [
                    'metric_key' => $metricKey,
                    'value' => $this->computeMetricValue($board, $metricKey, $start, $end, $memberIds[0] ?? null),
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
            'pace_alerts' => $this->computePaceAlerts($myTargets),
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

        return $row?->config_json ?? $this->defaultChartConfig($board);
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
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);

        return PipelineBoardTarget::query()
            ->where('board_id', $board->id)
            ->where('status', '!=', 'archived')
            ->with(['member:id,name,avatar', 'keyResults.member:id,name,avatar', 'allocations'])
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (PipelineBoardTarget $t) => $t->type !== 'key_result')
            ->map(fn (PipelineBoardTarget $t) => $this->serializeTargetTree($t, $board))
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function storeTarget(int $businessId, User $user, int $boardId, array $data): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);
        $this->pipeline->ensureCanManageBoard($user, $board);

        $validated = $this->validateTargetPayload($data, $board);
        $target = PipelineBoardTarget::create([
            ...$validated,
            'business_id' => $businessId,
            'board_id' => $board->id,
            'created_by' => $user->id,
        ]);

        if (! empty($data['allocations']) && is_array($data['allocations'])) {
            $this->decomposition->persistAllocations($businessId, $target, $data['allocations'], $user);
        } elseif (! empty($data['planning_level'])) {
            $preview = $this->decomposition->preview($businessId, $board, [
                'planning_level' => $data['planning_level'],
                'target_value' => $validated['target_value'],
                'anchor_start' => $validated['anchor_start'] ?? $validated['period_start'],
                'anchor_end' => $validated['anchor_end'] ?? $validated['period_end'],
                'stage_ids' => $validated['stage_id'] ? [$validated['stage_id']] : ($data['stage_ids'] ?? []),
                'member_user_ids' => $validated['member_user_id'] ? [(int) $validated['member_user_id']] : [],
                'decomposition_mode' => $validated['decomposition_mode'] ?? 'hybrid',
            ]);
            $this->decomposition->persistAllocations($businessId, $target, $preview['nodes'], $user);
        }

        if (! empty($data['key_results']) && is_array($data['key_results'])) {
            foreach ($data['key_results'] as $kr) {
                $krPayload = $this->validateTargetPayload([
                    ...$kr,
                    'type' => 'key_result',
                    'parent_id' => $target->id,
                    'period_type' => $validated['period_type'],
                    'period_start' => $validated['period_start'],
                    'period_end' => $validated['period_end'],
                    'stage_id' => $kr['stage_id'] ?? $validated['stage_id'],
                ], $board);
                PipelineBoardTarget::create([
                    ...$krPayload,
                    'business_id' => $businessId,
                    'board_id' => $board->id,
                    'parent_id' => $target->id,
                    'type' => 'key_result',
                    'created_by' => $user->id,
                ]);
            }
        }

        $target->load(['member:id,name,avatar', 'keyResults.member:id,name,avatar', 'allocations']);

        return $this->serializeTargetTree($target->fresh(['keyResults', 'allocations']), $board);
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateTarget(int $businessId, User $user, int $targetId, array $data): array
    {
        $target = $this->findTargetForBusiness($businessId, $targetId);
        $board = $this->pipeline->getBoard($businessId, $user, (int) $target->board_id);
        $this->pipeline->ensureCanManageBoard($user, $board);

        $validated = $this->validateTargetPayload($data, $board, $target);
        $target->update($validated);

        if (! empty($data['allocations']) && is_array($data['allocations'])) {
            $this->decomposition->persistAllocations($businessId, $target, $data['allocations'], $user);
        }

        $target->load(['member:id,name,avatar', 'keyResults.member:id,name,avatar', 'allocations']);

        return $this->serializeTargetTree($target, $board);
    }

    public function archiveTarget(int $businessId, User $user, int $targetId): void
    {
        $target = $this->findTargetForBusiness($businessId, $targetId);
        $board = $this->pipeline->getBoard($businessId, $user, (int) $target->board_id);
        $this->pipeline->ensureCanManageBoard($user, $board);

        $target->update(['status' => 'archived']);
        PipelineBoardTarget::query()
            ->where('parent_id', $target->id)
            ->update(['status' => 'archived']);
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

        foreach (self::METRIC_KEYS as $metricKey) {
            if (in_array($metricKey, ['win_rate', 'conversion_rate', 'avg_cycle_days'], true)) {
                continue;
            }

            $teamValue = $this->computeMetricValue($board, $metricKey, $start, $end, null);
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

    /** @return array{0: Carbon, 1: Carbon} */
    public function resolvePeriod(string $periodType, ?string $from, ?string $to): array
    {
        $now = now();

        return match ($periodType) {
            'day' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'quarter' => [$now->copy()->firstOfQuarter(), $now->copy()->lastOfQuarter()->endOfDay()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'custom' => [
                Carbon::parse($from ?? $now->copy()->startOfMonth()->toDateString())->startOfDay(),
                Carbon::parse($to ?? $now->toDateString())->endOfDay(),
            ],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    /** @return array<string, mixed> */
    protected function boardContext(PipelineBoard $board): array
    {
        $isProjectBoard = (bool) $board->project_id;
        $isEstimatesWorkspace = $board->workspace === 'estimates';
        $usesTaskLanguage = $isProjectBoard || $isEstimatesWorkspace;
        $board->loadMissing('business');

        return [
            'is_project_board' => $isProjectBoard,
            'is_pipeline_board' => ! $isProjectBoard && ($board->workspace === 'pipeline' || ! $board->workspace),
            'uses_task_language' => $usesTaskLanguage,
            'item_singular' => $usesTaskLanguage ? 'task' : 'lead',
            'item_plural' => $usesTaskLanguage ? 'tasks' : 'leads',
            'board_kind' => $isProjectBoard ? 'project' : ($isEstimatesWorkspace ? 'estimates' : 'pipeline'),
            'won_label' => $usesTaskLanguage ? 'completed' : 'won',
            'lost_label' => $usesTaskLanguage ? 'cancelled' : 'lost',
            'currency' => $board->business?->currency ?? 'UGX',
        ];
    }

    /** @return array<string, mixed> */
    protected function computeTeamMetrics(PipelineBoard $board, Carbon $start, Carbon $end): array
    {
        $metrics = [];
        foreach (self::METRIC_KEYS as $key) {
            $metrics[$key] = $this->computeMetricValue($board, $key, $start, $end, null);
        }

        return $metrics;
    }

    /** @return list<array<string, mixed>> */
    protected function computeMemberMetrics(PipelineBoard $board, Carbon $start, Carbon $end): array
    {
        $memberIds = $this->boardMemberIds($board);
        $users = User::query()->whereIn('id', $memberIds)->orderBy('name')->get(['id', 'name', 'avatar']);

        return $users->map(function (User $member) use ($board, $start, $end) {
            $metrics = [];
            foreach ([
                'cards_created',
                'cards_won',
                'cards_lost',
                'cards_open',
                'pipeline_value_won',
                'comments_posted',
                'checklist_items_done',
                'cards_moved',
            ] as $key) {
                $metrics[$key] = $this->computeMetricValue($board, $key, $start, $end, (int) $member->id);
            }

            return [
                'user_id' => (int) $member->id,
                'name' => $member->name,
                'avatar' => $member->avatar,
                'metrics' => $metrics,
            ];
        })->values()->all();
    }

    /** @return list<array{date: string, cards_created: int, cards_won: int, cards_lost: int, pipeline_value_won: float}> */
    protected function computeTrendSeries(PipelineBoard $board, Carbon $start, Carbon $end): array
    {
        $period = CarbonPeriod::create($start->copy()->startOfDay(), '1 day', $end->copy()->startOfDay());
        $series = [];

        foreach ($period as $day) {
            $dayStart = $day->copy()->startOfDay();
            $dayEnd = $day->copy()->endOfDay();
            $series[] = [
                'date' => $day->toDateString(),
                'cards_created' => (int) $this->computeMetricValue($board, 'cards_created', $dayStart, $dayEnd, null),
                'cards_won' => (int) $this->computeMetricValue($board, 'cards_won', $dayStart, $dayEnd, null),
                'cards_lost' => (int) $this->computeMetricValue($board, 'cards_lost', $dayStart, $dayEnd, null),
                'pipeline_value_won' => (float) $this->computeMetricValue($board, 'pipeline_value_won', $dayStart, $dayEnd, null),
            ];
        }

        return $series;
    }

    /** @return list<array<string, mixed>> */
    protected function computeStageFunnel(PipelineBoard $board, Carbon $start, Carbon $end): array
    {
        $board->loadMissing('stages');

        return $board->stages->map(function ($stage) use ($board, $start, $end) {
            $count = PipelineLead::query()
                ->where('board_id', $board->id)
                ->where('stage_id', $stage->id)
                ->whereIn('status', ['open', 'won', 'lost', 'converted'])
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('created_at', [$start, $end])
                        ->orWhereBetween('updated_at', [$start, $end]);
                })
                ->count();

            $value = (float) PipelineLead::query()
                ->where('board_id', $board->id)
                ->where('stage_id', $stage->id)
                ->where('status', 'open')
                ->sum('estimated_value');

            return [
                'stage_id' => (int) $stage->id,
                'stage_name' => $stage->name,
                'color' => $stage->color,
                'count' => $count,
                'open_value' => round($value, 2),
                'is_won' => (bool) $stage->is_won,
                'is_lost' => (bool) $stage->is_lost,
            ];
        })->values()->all();
    }

    protected function computeMetricValue(
        PipelineBoard $board,
        string $metricKey,
        Carbon $start,
        Carbon $end,
        ?int $memberUserId,
    ): float {
        $leadQuery = PipelineLead::query()->where('board_id', $board->id);

        if ($memberUserId) {
            $leadIds = PipelineLeadAssignee::query()
                ->where('user_id', $memberUserId)
                ->pluck('lead_id')
                ->merge(
                    PipelineLead::query()
                        ->where('board_id', $board->id)
                        ->where('assigned_to', $memberUserId)
                        ->pluck('id'),
                )
                ->unique()
                ->values();

            $leadQuery->whereIn('id', $leadIds);
        }

        if (! in_array($metricKey, self::METRIC_KEYS, true)) {
            $parsed = $this->columnMetrics->parseStageMetricKey($metricKey);
            if ($parsed && in_array($parsed['suffix'], PipelineColumnMetricsService::COLUMN_METRIC_SUFFIXES, true)) {
                return $this->columnMetrics->computeStageMetric(
                    $board,
                    $parsed['stage_id'],
                    $parsed['suffix'],
                    $start,
                    $end,
                    $memberUserId,
                );
            }

            throw ValidationException::withMessages(['metric_key' => 'Invalid metric key.']);
        }

        return match ($metricKey) {
            'cards_created' => (float) (clone $leadQuery)
                ->whereBetween('created_at', [$start, $end])
                ->count(),
            'cards_won' => (float) (clone $leadQuery)
                ->where('status', 'won')
                ->whereBetween('won_at', [$start, $end])
                ->count(),
            'cards_lost' => (float) (clone $leadQuery)
                ->where('status', 'lost')
                ->whereBetween('lost_at', [$start, $end])
                ->count(),
            'cards_converted' => (float) (clone $leadQuery)
                ->where('status', 'converted')
                ->whereBetween('converted_at', [$start, $end])
                ->count(),
            'cards_open' => (float) (clone $leadQuery)
                ->where('status', 'open')
                ->count(),
            'pipeline_value_open' => round((float) (clone $leadQuery)
                ->where('status', 'open')
                ->sum('estimated_value'), 2),
            'pipeline_value_won' => round((float) (clone $leadQuery)
                ->where('status', 'won')
                ->whereBetween('won_at', [$start, $end])
                ->sum('estimated_value'), 2),
            'win_rate' => $this->computeWinRate($board, $start, $end, $memberUserId),
            'conversion_rate' => $this->computeConversionRate($board, $start, $end, $memberUserId),
            'avg_cycle_days' => $this->computeAvgCycleDays($board, $start, $end, $memberUserId),
            'cards_moved' => $this->countActivities($board, ['stage_change'], $start, $end, $memberUserId),
            'comments_posted' => $this->countActivities($board, ['comment', 'note'], $start, $end, $memberUserId),
            'checklist_items_done' => $this->countChecklistItemsDone($board, $start, $end, $memberUserId),
            'overdue_cards' => (float) (clone $leadQuery)
                ->where('status', 'open')
                ->whereNotNull('expected_close_date')
                ->whereDate('expected_close_date', '<', now()->toDateString())
                ->count(),
            default => 0.0,
        };
    }

    protected function computeWinRate(PipelineBoard $board, Carbon $start, Carbon $end, ?int $memberUserId): float
    {
        $won = $this->computeMetricValue($board, 'cards_won', $start, $end, $memberUserId);
        $lost = $this->computeMetricValue($board, 'cards_lost', $start, $end, $memberUserId);
        $closed = $won + $lost;

        return $closed > 0 ? round(($won / $closed) * 100, 1) : 0.0;
    }

    protected function computeConversionRate(PipelineBoard $board, Carbon $start, Carbon $end, ?int $memberUserId): float
    {
        $converted = $this->computeMetricValue($board, 'cards_converted', $start, $end, $memberUserId);
        $created = $this->computeMetricValue($board, 'cards_created', $start, $end, $memberUserId);

        return $created > 0 ? round(($converted / $created) * 100, 1) : 0.0;
    }

    protected function computeAvgCycleDays(PipelineBoard $board, Carbon $start, Carbon $end, ?int $memberUserId): float
    {
        $query = PipelineLead::query()
            ->where('board_id', $board->id)
            ->where('status', 'won')
            ->whereNotNull('won_at')
            ->whereBetween('won_at', [$start, $end]);

        if ($memberUserId) {
            $query->where(function ($q) use ($memberUserId) {
                $q->where('assigned_to', $memberUserId)
                    ->orWhereIn('id', PipelineLeadAssignee::query()->where('user_id', $memberUserId)->select('lead_id'));
            });
        }

        $leads = $query->get(['created_at', 'won_at']);
        if ($leads->isEmpty()) {
            return 0.0;
        }

        $totalDays = $leads->sum(fn ($lead) => $lead->created_at->diffInDays($lead->won_at));

        return round($totalDays / $leads->count(), 1);
    }

  /** @param  list<string>  $types */
    protected function countActivities(
        PipelineBoard $board,
        array $types,
        Carbon $start,
        Carbon $end,
        ?int $memberUserId,
    ): float {
        $query = PipelineLeadActivity::query()
            ->where('business_id', $board->business_id)
            ->whereIn('type', $types)
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('lead_id', PipelineLead::query()->where('board_id', $board->id)->select('id'));

        if ($memberUserId) {
            $query->where('user_id', $memberUserId);
        }

        return (float) $query->count();
    }

    protected function countChecklistItemsDone(
        PipelineBoard $board,
        Carbon $start,
        Carbon $end,
        ?int $memberUserId,
    ): float {
        $query = PipelineChecklistItem::query()
            ->where('is_done', true)
            ->whereBetween('updated_at', [$start, $end])
            ->whereHas('checklist.lead', fn ($q) => $q->where('board_id', $board->id));

        if ($memberUserId) {
            $query->whereHas('checklist.lead', function ($q) use ($memberUserId) {
                $q->where('assigned_to', $memberUserId)
                    ->orWhereIn('id', PipelineLeadAssignee::query()->where('user_id', $memberUserId)->select('lead_id'));
            });
        }

        return (float) $query->count();
    }

    /** @return list<int> */
    protected function boardMemberIds(PipelineBoard $board): array
    {
        $fromCards = PipelineLead::query()
            ->where('board_id', $board->id)
            ->whereNotNull('assigned_to')
            ->distinct()
            ->pluck('assigned_to');

        $fromPivot = PipelineLeadAssignee::query()
            ->whereIn('lead_id', PipelineLead::query()->where('board_id', $board->id)->select('id'))
            ->distinct()
            ->pluck('user_id');

        $fromActivity = PipelineLeadActivity::query()
            ->whereIn('lead_id', PipelineLead::query()->where('board_id', $board->id)->select('id'))
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        return $fromCards->merge($fromPivot)->merge($fromActivity)->merge([$board->created_by])
            ->unique()->filter()->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @return list<array<string, mixed>> */
    protected function listTargetsWithProgress(
        int $businessId,
        User $user,
        int $boardId,
        Carbon $start,
        Carbon $end,
        ?string $viewPeriodType = null,
    ): array {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);

        return PipelineBoardTarget::query()
            ->where('board_id', $board->id)
            ->where('status', '!=', 'archived')
            ->where('type', '!=', 'key_result')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('period_start', [$start->toDateString(), $end->toDateString()])
                    ->orWhereBetween('period_end', [$start->toDateString(), $end->toDateString()])
                    ->orWhere(function ($inner) use ($start, $end) {
                        $inner->where('period_start', '<=', $start->toDateString())
                            ->where('period_end', '>=', $end->toDateString());
                    });
            })
            ->with(['member:id,name,avatar', 'keyResults.member:id,name,avatar', 'allocations'])
            ->orderBy('type')
            ->orderBy('title')
            ->get()
            ->map(fn (PipelineBoardTarget $t) => $this->serializeTargetTree($t, $board, $start, $end, $viewPeriodType))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    protected function serializeTargetTree(
        PipelineBoardTarget $target,
        PipelineBoard $board,
        ?Carbon $start = null,
        ?Carbon $end = null,
        ?string $viewPeriodType = null,
    ): array {
        $periodStart = $start ?? Carbon::parse($target->period_start)->startOfDay();
        $periodEnd = $end ?? Carbon::parse($target->period_end)->endOfDay();
        $memberId = $target->scope === 'member' ? (int) $target->member_user_id : null;
        $actual = $this->computeMetricValue($board, $target->metric_key, $periodStart, $periodEnd, $memberId);
        $progressPercent = $this->progressPercent($actual, (float) $target->target_value);
        $paceStatus = $this->paceStatus($actual, (float) $target->target_value, $periodStart, $periodEnd, $target->metric_key);
        $periodSlice = null;

        if ($start !== null && $end !== null) {
            $periodSlice = $this->buildPeriodSlice($target, $board, $periodStart, $periodEnd, $viewPeriodType);
            if ($periodSlice !== null) {
                $actual = $periodSlice['actual_value'];
                $progressPercent = $periodSlice['progress_percent'];
                $paceStatus = $periodSlice['pace_status'];
            }
        }

        $payload = [
            'id' => (int) $target->id,
            'parent_id' => $target->parent_id ? (int) $target->parent_id : null,
            'type' => $target->type,
            'title' => $target->title,
            'description' => $target->description,
            'metric_key' => $target->metric_key,
            'target_value' => (float) $target->target_value,
            'actual_value' => $actual,
            'unit' => $target->unit,
            'period_type' => $target->period_type,
            'period_start' => $target->period_start?->toDateString(),
            'period_end' => $target->period_end?->toDateString(),
            'scope' => $target->scope,
            'member_user_id' => $target->member_user_id ? (int) $target->member_user_id : null,
            'member' => $target->member ? [
                'id' => (int) $target->member->id,
                'name' => $target->member->name,
                'avatar' => $target->member->avatar,
            ] : null,
            'weight' => (int) $target->weight,
            'status' => $target->status,
            'progress_percent' => $progressPercent,
            'pace_status' => $paceStatus,
            'planning_level' => $target->planning_level,
            'anchor_start' => $target->anchor_start?->toDateString(),
            'anchor_end' => $target->anchor_end?->toDateString(),
            'stage_id' => $target->stage_id ? (int) $target->stage_id : null,
            'goal_tag' => $target->goal_tag,
            'decomposition_mode' => $target->decomposition_mode,
            'allocations' => $target->relationLoaded('allocations')
                ? $this->decomposition->allocationsForTarget($target)
                : [],
            'key_results' => [],
        ];

        if ($periodSlice !== null) {
            $payload['period_slice'] = $periodSlice;
        }

        if ($target->relationLoaded('keyResults')) {
            $payload['key_results'] = $target->keyResults
                ->where('status', '!=', 'archived')
                ->map(fn (PipelineBoardTarget $kr) => $this->serializeTargetTree($kr, $board, $periodStart, $periodEnd, $viewPeriodType))
                ->values()
                ->all();

            if ($target->type === 'objective' && count($payload['key_results']) > 0 && ! isset($payload['period_slice'])) {
                $payload['progress_percent'] = round(
                    collect($payload['key_results'])->avg('progress_percent') ?? 0,
                    1,
                );
            }
        }

        return $payload;
    }

    protected function progressPercent(float $actual, float $target): float
    {
        if ($target <= 0) {
            return 0.0;
        }

        return min(100.0, round(($actual / $target) * 100, 1));
    }

    protected function paceStatus(
        float $actual,
        float $target,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $metricKey,
    ): string {
        if ($target <= 0) {
            return 'on_track';
        }

        if ($actual >= $target) {
            return 'achieved';
        }

        $lowerIsBetter = $metricKey === 'avg_cycle_days';
        $totalDays = max(1, $periodStart->diffInDays($periodEnd) + 1);
        $elapsedDays = max(1, $periodStart->diffInDays(min(now(), $periodEnd)) + 1);
        $expectedPace = ($target / $totalDays) * $elapsedDays;

        if ($lowerIsBetter) {
            if ($actual <= $expectedPace * 0.9) {
                return 'on_track';
            }
            if ($actual <= $expectedPace * 1.1) {
                return 'at_risk';
            }

            return 'behind';
        }

        if ($actual >= $expectedPace * 0.9) {
            return 'on_track';
        }
        if ($actual >= $expectedPace * 0.7) {
            return 'at_risk';
        }

        return 'behind';
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validateTargetPayload(array $data, PipelineBoard $board, ?PipelineBoardTarget $existing = null): array
    {
        $type = $data['type'] ?? $existing?->type;
        $metricKey = $data['metric_key'] ?? $existing?->metric_key;

        if (! in_array($type, ['kpi', 'goal', 'objective', 'key_result'], true)) {
            throw ValidationException::withMessages(['type' => 'Invalid target type.']);
        }

        if (! in_array($metricKey, self::METRIC_KEYS, true) && ! $this->columnMetrics->parseStageMetricKey($metricKey)) {
            throw ValidationException::withMessages(['metric_key' => 'Invalid metric key.']);
        }

        $periodType = $data['period_type'] ?? $existing?->period_type ?? 'month';
        $planningLevel = $data['planning_level'] ?? $existing?->planning_level;

        if ($planningLevel && ! $existing) {
            $anchorStart = isset($data['anchor_start'])
                ? Carbon::parse($data['anchor_start'])
                : Carbon::parse($this->decomposition->defaultAnchorStart($planningLevel));
            $anchorEnd = isset($data['anchor_end'])
                ? Carbon::parse($data['anchor_end'])
                : Carbon::parse($this->decomposition->defaultAnchorEnd($planningLevel, $anchorStart));
            $start = $anchorStart->copy()->startOfDay();
            $end = $anchorEnd->copy()->endOfDay();
        } elseif (isset($data['period_start'], $data['period_end'])) {
            $start = Carbon::parse($data['period_start'])->startOfDay();
            $end = Carbon::parse($data['period_end'])->endOfDay();
            $anchorStart = isset($data['anchor_start'])
                ? Carbon::parse($data['anchor_start'])
                : $start->copy();
            $anchorEnd = isset($data['anchor_end'])
                ? Carbon::parse($data['anchor_end'])
                : $end->copy();
        } else {
            [$start, $end] = $this->resolvePeriod($periodType, $data['period_from'] ?? null, $data['period_to'] ?? null);
            $anchorStart = isset($data['anchor_start'])
                ? Carbon::parse($data['anchor_start'])
                : ($existing?->anchor_start ? Carbon::parse($existing->anchor_start) : $start->copy());
            $anchorEnd = isset($data['anchor_end'])
                ? Carbon::parse($data['anchor_end'])
                : ($existing?->anchor_end ? Carbon::parse($existing->anchor_end) : $end->copy());
        }

        $scope = $data['scope'] ?? $existing?->scope ?? 'board';
        $memberUserId = $data['member_user_id'] ?? $existing?->member_user_id;
        $stageId = $data['stage_id'] ?? $existing?->stage_id;

        if ($type !== 'key_result' && empty($stageId) && empty($existing?->stage_id)) {
            throw ValidationException::withMessages(['stage_id' => 'Select a board column for this target.']);
        }

        if ($scope === 'member' && ! $memberUserId) {
            throw ValidationException::withMessages(['member_user_id' => 'Select a team member for member-scoped targets.']);
        }

        if ($type === 'key_result' && empty($data['parent_id']) && ! $existing?->parent_id) {
            throw ValidationException::withMessages(['parent_id' => 'Key results must belong to an objective.']);
        }

        return [
            'type' => $type,
            'goal_tag' => $data['goal_tag'] ?? $existing?->goal_tag ?? $type,
            'title' => $data['title'] ?? $existing?->title,
            'description' => $data['description'] ?? $existing?->description,
            'metric_key' => $metricKey,
            'target_value' => (float) ($data['target_value'] ?? $existing?->target_value ?? 0),
            'unit' => $data['unit'] ?? $existing?->unit ?? 'count',
            'period_type' => $periodType,
            'planning_level' => $planningLevel,
            'anchor_start' => $anchorStart->toDateString(),
            'anchor_end' => $anchorEnd->toDateString(),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'scope' => $scope,
            'member_user_id' => $scope === 'member' ? (int) $memberUserId : null,
            'stage_id' => $stageId ? (int) $stageId : null,
            'weight' => (int) ($data['weight'] ?? $existing?->weight ?? 100),
            'status' => $data['status'] ?? $existing?->status ?? 'active',
            'decomposition_mode' => $data['decomposition_mode'] ?? $existing?->decomposition_mode ?? 'hybrid',
            'parent_id' => $data['parent_id'] ?? $existing?->parent_id,
        ];
    }

    /** @param  list<int>|null  $stageIds
     * @return list<int>
     */
    protected function resolveStageIds(PipelineBoard $board, ?array $stageIds): array
    {
        $board->loadMissing('stages');
        $all = $board->stages->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($stageIds === null || $stageIds === []) {
            return $all;
        }

        return array_values(array_intersect(array_map('intval', $stageIds), $all));
    }

    /** @return array<string, mixed> */
    protected function defaultChartConfig(PipelineBoard $board): array
    {
        $board->loadMissing('stages');
        $stageIds = $board->stages->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            'charts' => [
                ['id' => 'funnel', 'type' => 'bar', 'metric' => 'count', 'stage_ids' => $stageIds],
                ['id' => 'trend', 'type' => 'line', 'metrics' => ['cards_created', 'cards_won', 'cards_lost'], 'stage_ids' => $stageIds],
                ['id' => 'column_throughput', 'type' => 'bar', 'metric' => 'throughput', 'stage_ids' => $stageIds],
            ],
            'funnel_mode' => 'count',
        ];
    }

    /** @return array<string, mixed>|null */
    protected function buildPeriodSlice(
        PipelineBoardTarget $target,
        PipelineBoard $board,
        Carbon $viewStart,
        Carbon $viewEnd,
        ?string $viewPeriodType,
    ): ?array {
        $memberId = $target->scope === 'member' ? (int) $target->member_user_id : null;
        $stageId = $target->stage_id ? (int) $target->stage_id : null;
        $allocations = $target->relationLoaded('allocations')
            ? $target->allocations
            : $target->allocations()->get();

        if ($allocations->isEmpty()) {
            return $this->buildFallbackPeriodSlice($target, $board, $viewStart, $viewEnd, $memberId, $viewPeriodType);
        }

        $preferredLevel = $viewPeriodType
            ? $this->decomposition->viewPeriodToPlanningLevel($viewPeriodType)
            : null;

        $rows = $this->decomposition->resolveSliceAllocations(
            $allocations,
            $viewStart,
            $viewEnd,
            $preferredLevel,
            $stageId,
            $memberId,
        );

        $expected = 0.0;
        $planningLevel = $preferredLevel ?? 'month';

        if ($rows !== []) {
            // Prorate coarser allocations into the active view (e.g. month goal → day = x/2).
            $expected = $this->sumProratedAllocationExpected($rows, $viewStart, $viewEnd);
            $planningLevel = $preferredLevel ?? $rows[0]->planning_level;
        } elseif ($viewPeriodType === 'custom' || $preferredLevel === null) {
            $expected = $this->decomposition->sumDailyExpectedInView(
                $allocations,
                $viewStart,
                $viewEnd,
                $stageId,
                $memberId,
            );
            $planningLevel = 'day';
        }

        if ($expected <= 0) {
            return $this->buildFallbackPeriodSlice($target, $board, $viewStart, $viewEnd, $memberId, $viewPeriodType);
        }

        // Achievement window is always the selected Progress period (Today / week / month…).
        $sliceStart = $viewStart->copy()->startOfDay();
        $sliceEnd = $viewEnd->copy()->endOfDay();

        $actual = $this->computeMetricValue($board, $target->metric_key, $viewStart, $viewEnd, $memberId);
        $expectedToDate = $this->decomposition->expectedToDate($expected, $sliceStart, $sliceEnd, now());

        return [
            'planning_level' => $planningLevel,
            'period_start' => $sliceStart->toDateString(),
            'period_end' => $sliceEnd->toDateString(),
            'view_period_type' => $viewPeriodType,
            'expected_value' => round($expected, 4),
            'expected_to_date' => round($expectedToDate, 4),
            'actual_value' => $actual,
            'progress_percent' => $this->progressPercent($actual, $expected),
            'pace_status' => $this->paceStatus($actual, $expected, $sliceStart, $sliceEnd, $target->metric_key),
            'root_target_value' => (float) $target->target_value,
        ];
    }

    /**
     * Sum allocation expected values clipped to the Progress view window.
     * A full-month row of 60 over 30 days becomes 2 for a single-day view.
     *
     * @param  list<\App\Models\PipelineBoardTargetAllocation>  $rows
     */
    protected function sumProratedAllocationExpected(array $rows, Carbon $viewStart, Carbon $viewEnd): float
    {
        $expected = 0.0;

        foreach ($rows as $row) {
            $rowStart = Carbon::parse($row->period_start)->startOfDay();
            $rowEnd = Carbon::parse($row->period_end)->endOfDay();
            $overlapStart = $viewStart->greaterThan($rowStart) ? $viewStart->copy() : $rowStart->copy();
            $overlapEnd = $viewEnd->lessThan($rowEnd) ? $viewEnd->copy() : $rowEnd->copy();

            if ($overlapStart->gt($overlapEnd)) {
                continue;
            }

            $rowDays = max(1, $rowStart->diffInDays($rowEnd) + 1);
            $overlapDays = max(1, $overlapStart->diffInDays($overlapEnd) + 1);
            $expected += (float) $row->expected_value * ($overlapDays / $rowDays);
        }

        return $expected;
    }

    /** @return array<string, mixed>|null */
    protected function buildFallbackPeriodSlice(
        PipelineBoardTarget $target,
        PipelineBoard $board,
        Carbon $viewStart,
        Carbon $viewEnd,
        ?int $memberId,
        ?string $viewPeriodType,
    ): ?array {
        $anchorStart = Carbon::parse($target->anchor_start ?? $target->period_start)->startOfDay();
        $anchorEnd = Carbon::parse($target->anchor_end ?? $target->period_end)->endOfDay();

        if ($viewEnd->lt($anchorStart) || $viewStart->gt($anchorEnd)) {
            return null;
        }

        $sliceStart = $viewStart->gt($anchorStart) ? $viewStart->copy() : $anchorStart->copy();
        $sliceEnd = $viewEnd->lt($anchorEnd) ? $viewEnd->copy() : $anchorEnd->copy();
        $totalDays = max(1, $anchorStart->diffInDays($anchorEnd) + 1);
        $sliceDays = max(1, $sliceStart->diffInDays($sliceEnd) + 1);
        $expected = (float) $target->target_value * ($sliceDays / $totalDays);
        $actual = $this->computeMetricValue($board, $target->metric_key, $viewStart, $viewEnd, $memberId);
        $expectedToDate = $this->decomposition->expectedToDate($expected, $sliceStart, $sliceEnd, now());
        $planningLevel = $viewPeriodType
            ? ($this->decomposition->viewPeriodToPlanningLevel($viewPeriodType) ?? 'month')
            : ($target->planning_level ?? 'month');

        return [
            'planning_level' => $planningLevel,
            'period_start' => $sliceStart->toDateString(),
            'period_end' => $sliceEnd->toDateString(),
            'view_period_type' => $viewPeriodType,
            'expected_value' => round($expected, 4),
            'expected_to_date' => round($expectedToDate, 4),
            'actual_value' => $actual,
            'progress_percent' => $this->progressPercent($actual, $expected),
            'pace_status' => $this->paceStatus($actual, $expected, $sliceStart, $sliceEnd, $target->metric_key),
            'root_target_value' => (float) $target->target_value,
        ];
    }

    /** @param  list<array<string, mixed>>  $targets
     * @return list<array<string, mixed>>
     */
    protected function computePaceAlerts(array $targets): array
    {
        $alerts = [];
        foreach ($targets as $target) {
            if (in_array($target['pace_status'] ?? '', ['behind', 'at_risk'], true)) {
                $alerts[] = [
                    'target_id' => $target['id'],
                    'title' => $target['title'],
                    'pace_status' => $target['pace_status'],
                    'progress_percent' => $target['progress_percent'],
                ];
            }
        }

        return $alerts;
    }

    /** @return list<array{date: string, expected: float}> */
    protected function computeExpectedTrendSeries(
        PipelineBoard $board,
        Carbon $start,
        Carbon $end,
        array $stageIds,
    ): array {
        $allocations = PipelineBoardTargetAllocation::query()
            ->whereIn('target_id', PipelineBoardTarget::query()->where('board_id', $board->id)->where('status', 'active')->select('id'))
            ->where('planning_level', 'day')
            ->whereBetween('period_start', [$start->toDateString(), $end->toDateString()])
            ->when($stageIds !== [], fn ($q) => $q->whereIn('stage_id', $stageIds))
            ->get();

        $period = CarbonPeriod::create($start->copy()->startOfDay(), '1 day', $end->copy()->startOfDay());
        $series = [];
        foreach ($period as $day) {
            $date = $day->toDateString();
            $expected = $allocations->where('period_start', '<=', $date)
                ->where('period_end', '>=', $date)
                ->sum('expected_value');
            $series[] = ['date' => $date, 'expected' => round((float) $expected, 2)];
        }

        return $series;
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
        return $this->serializeTargetTree($target, $board, $start, $end, $viewPeriodType);
    }

    protected function findTargetForBusiness(int $businessId, int $targetId): PipelineBoardTarget
    {
        return PipelineBoardTarget::query()
            ->where('business_id', $businessId)
            ->whereKey($targetId)
            ->firstOrFail();
    }
}
