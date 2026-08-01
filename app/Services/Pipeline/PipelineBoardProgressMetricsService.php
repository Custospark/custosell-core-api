<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardTarget;
use App\Models\PipelineBoardTargetAllocation;
use App\Models\PipelineChecklistItem;
use App\Models\PipelineLead;
use App\Models\PipelineLeadActivity;
use App\Models\PipelineLeadAssignee;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Validation\ValidationException;

class PipelineBoardProgressMetricsService
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
        protected PipelineColumnMetricsService $columnMetrics,
    ) {}

    /** @return array<string, mixed> */
    public function boardContext(PipelineBoard $board): array
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
    public function computeTeamMetrics(PipelineBoard $board, Carbon $start, Carbon $end): array
    {
        $metrics = [];
        foreach (self::METRIC_KEYS as $key) {
            $metrics[$key] = $this->computeMetricValue($board, $key, $start, $end, null);
        }

        return $metrics;
    }

    /** @return list<array<string, mixed>> */
    public function computeMemberMetrics(PipelineBoard $board, Carbon $start, Carbon $end): array
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
    public function computeTrendSeries(PipelineBoard $board, Carbon $start, Carbon $end): array
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
    public function computeStageFunnel(PipelineBoard $board, Carbon $start, Carbon $end): array
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

    public function computeMetricValue(
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

    public function computeWinRate(PipelineBoard $board, Carbon $start, Carbon $end, ?int $memberUserId): float
    {
        $won = $this->computeMetricValue($board, 'cards_won', $start, $end, $memberUserId);
        $lost = $this->computeMetricValue($board, 'cards_lost', $start, $end, $memberUserId);
        $closed = $won + $lost;

        return $closed > 0 ? round(($won / $closed) * 100, 1) : 0.0;
    }

    public function computeConversionRate(PipelineBoard $board, Carbon $start, Carbon $end, ?int $memberUserId): float
    {
        $converted = $this->computeMetricValue($board, 'cards_converted', $start, $end, $memberUserId);
        $created = $this->computeMetricValue($board, 'cards_created', $start, $end, $memberUserId);

        return $created > 0 ? round(($converted / $created) * 100, 1) : 0.0;
    }

    public function computeAvgCycleDays(PipelineBoard $board, Carbon $start, Carbon $end, ?int $memberUserId): float
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
    public function countActivities(
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

    public function countChecklistItemsDone(
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
    public function boardMemberIds(PipelineBoard $board): array
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

    /** @return list<array{date: string, expected: float}> */
    public function computeExpectedTrendSeries(
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
}
