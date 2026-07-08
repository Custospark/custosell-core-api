<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardMetricSnapshot;
use App\Models\PipelineBoardTarget;
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
    ) {}

    /** @return array<string, mixed> */
    public function progressSummary(
        int $businessId,
        User $user,
        int $boardId,
        string $periodType = 'month',
        ?string $from = null,
        ?string $to = null,
    ): array {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);
        [$start, $end] = $this->resolvePeriod($periodType, $from, $to);
        $context = $this->boardContext($board);

        $teamMetrics = $this->computeTeamMetrics($board, $start, $end);
        $memberMetrics = $this->computeMemberMetrics($board, $start, $end);
        $trends = $this->computeTrendSeries($board, $start, $end);
        $funnel = $this->computeStageFunnel($board, $start, $end);
        $targets = $this->listTargetsWithProgress($businessId, $user, $boardId, $start, $end);

        return [
            'board_id' => $board->id,
            'period' => [
                'type' => $periodType,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'context' => $context,
            'team' => $teamMetrics,
            'members' => $memberMetrics,
            'trends' => $trends,
            'funnel' => $funnel,
            'targets' => $targets,
            'can_manage_targets' => $this->pipeline->userCanManageBoard($user, $board),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listTargets(int $businessId, User $user, int $boardId): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);

        return PipelineBoardTarget::query()
            ->where('board_id', $board->id)
            ->where('status', '!=', 'archived')
            ->with(['member:id,name,avatar', 'keyResults.member:id,name,avatar'])
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

        if (! empty($data['key_results']) && is_array($data['key_results'])) {
            foreach ($data['key_results'] as $kr) {
                $krPayload = $this->validateTargetPayload([
                    ...$kr,
                    'type' => 'key_result',
                    'parent_id' => $target->id,
                    'period_type' => $validated['period_type'],
                    'period_start' => $validated['period_start'],
                    'period_end' => $validated['period_end'],
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

        $target->load(['member:id,name,avatar', 'keyResults.member:id,name,avatar']);

        return $this->serializeTargetTree($target->fresh(['keyResults']), $board);
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
        $target->load(['member:id,name,avatar', 'keyResults.member:id,name,avatar']);

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
            ->with(['member:id,name,avatar', 'keyResults.member:id,name,avatar'])
            ->orderBy('type')
            ->orderBy('title')
            ->get()
            ->map(fn (PipelineBoardTarget $t) => $this->serializeTargetTree($t, $board, $start, $end))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    protected function serializeTargetTree(
        PipelineBoardTarget $target,
        PipelineBoard $board,
        ?Carbon $start = null,
        ?Carbon $end = null,
    ): array {
        $periodStart = $start ?? Carbon::parse($target->period_start)->startOfDay();
        $periodEnd = $end ?? Carbon::parse($target->period_end)->endOfDay();
        $memberId = $target->scope === 'member' ? (int) $target->member_user_id : null;
        $actual = $this->computeMetricValue($board, $target->metric_key, $periodStart, $periodEnd, $memberId);

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
            'progress_percent' => $this->progressPercent($actual, (float) $target->target_value),
            'pace_status' => $this->paceStatus($actual, (float) $target->target_value, $periodStart, $periodEnd, $target->metric_key),
            'key_results' => [],
        ];

        if ($target->relationLoaded('keyResults')) {
            $payload['key_results'] = $target->keyResults
                ->where('status', '!=', 'archived')
                ->map(fn (PipelineBoardTarget $kr) => $this->serializeTargetTree($kr, $board, $periodStart, $periodEnd))
                ->values()
                ->all();

            if ($target->type === 'objective' && count($payload['key_results']) > 0) {
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

        if (! in_array($metricKey, self::METRIC_KEYS, true)) {
            throw ValidationException::withMessages(['metric_key' => 'Invalid metric key.']);
        }

        $periodType = $data['period_type'] ?? $existing?->period_type ?? 'month';
        [$start, $end] = isset($data['period_start'], $data['period_end'])
            ? [Carbon::parse($data['period_start']), Carbon::parse($data['period_end'])]
            : $this->resolvePeriod($periodType, $data['period_from'] ?? null, $data['period_to'] ?? null);

        $scope = $data['scope'] ?? $existing?->scope ?? 'board';
        $memberUserId = $data['member_user_id'] ?? $existing?->member_user_id;

        if ($scope === 'member' && ! $memberUserId) {
            throw ValidationException::withMessages(['member_user_id' => 'Select a team member for member-scoped targets.']);
        }

        if ($type === 'key_result' && empty($data['parent_id']) && ! $existing?->parent_id) {
            throw ValidationException::withMessages(['parent_id' => 'Key results must belong to an objective.']);
        }

        return [
            'type' => $type,
            'title' => $data['title'] ?? $existing?->title,
            'description' => $data['description'] ?? $existing?->description,
            'metric_key' => $metricKey,
            'target_value' => (float) ($data['target_value'] ?? $existing?->target_value ?? 0),
            'unit' => $data['unit'] ?? $existing?->unit ?? 'count',
            'period_type' => $periodType,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'scope' => $scope,
            'member_user_id' => $scope === 'member' ? (int) $memberUserId : null,
            'weight' => (int) ($data['weight'] ?? $existing?->weight ?? 100),
            'status' => $data['status'] ?? $existing?->status ?? 'active',
            'parent_id' => $data['parent_id'] ?? $existing?->parent_id,
        ];
    }

    protected function findTargetForBusiness(int $businessId, int $targetId): PipelineBoardTarget
    {
        return PipelineBoardTarget::query()
            ->where('business_id', $businessId)
            ->whereKey($targetId)
            ->firstOrFail();
    }
}
