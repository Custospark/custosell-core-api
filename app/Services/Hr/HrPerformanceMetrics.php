<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\PipelineBoardTarget;
use App\Models\PipelineLead;
use App\Models\ProjectTask;
use App\Services\Pipeline\PipelineBoardProgressService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data aggregation for Pipeline/Projects work performance: lead, task and
 * board-goal statistics plus recent activity snapshots.
 */
class HrPerformanceMetrics
{
    public function __construct(
        protected PipelineBoardProgressService $progress,
    ) {}

    /**
     * @return array<string, int|float>
     */
    public function leadStats(int $businessId, int $userId, Carbon $start, Carbon $end): array
    {
        $leadIds = $this->assignedLeadIds($businessId, $userId);
        if ($leadIds === []) {
            return $this->emptyLeadStats();
        }

        $today = Carbon::today()->toDateString();
        $rows = PipelineLead::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $leadIds)
            ->get(['id', 'status', 'due_date', 'won_at', 'lost_at', 'converted_at', 'created_at', 'updated_at']);

        $open = $rows->where('status', 'open')->count();
        $won = $rows->filter(fn (PipelineLead $lead) => $lead->status === 'won'
            && $lead->won_at
            && $lead->won_at->between($start, $end))->count();
        $lost = $rows->filter(fn (PipelineLead $lead) => $lead->status === 'lost'
            && $lead->lost_at
            && $lead->lost_at->between($start, $end))->count();
        $converted = $rows->filter(fn (PipelineLead $lead) => $lead->status === 'converted'
            && $lead->converted_at
            && $lead->converted_at->between($start, $end))->count();
        $touched = $rows->filter(fn (PipelineLead $lead) => ($lead->created_at && $lead->created_at->between($start, $end))
            || ($lead->updated_at && $lead->updated_at->between($start, $end))
            || ($lead->won_at && $lead->won_at->between($start, $end))
            || ($lead->lost_at && $lead->lost_at->between($start, $end))
            || ($lead->converted_at && $lead->converted_at->between($start, $end)))->count();
        $overdue = $rows
            ->filter(fn (PipelineLead $lead) => $lead->status === 'open'
                && $lead->due_date
                && $lead->due_date->toDateString() < $today)
            ->count();

        $closed = $won + $lost + $converted;
        $winRate = $closed > 0 ? round(($won / $closed) * 100, 1) : 0.0;

        return [
            'total' => $touched,
            'open' => $open,
            'won' => $won,
            'lost' => $lost,
            'converted' => $converted,
            'overdue' => $overdue,
            'win_rate' => $winRate,
        ];
    }

    /**
     * @return array<string, int|float>
     */
    public function projectTaskStats(int $businessId, int $userId, Carbon $start, Carbon $end): array
    {
        $rows = ProjectTask::query()
            ->where('assigned_to', $userId)
            ->whereHas('project', fn ($q) => $q->where('business_id', $businessId))
            ->get(['id', 'status', 'due_date', 'created_at', 'updated_at']);

        if ($rows->isEmpty()) {
            return $this->emptyTaskStats();
        }

        $today = Carbon::today()->toDateString();
        $done = $rows->filter(fn (ProjectTask $task) => $task->status === 'done'
            && $task->updated_at
            && $task->updated_at->between($start, $end))->count();
        $cancelled = $rows->filter(fn (ProjectTask $task) => $task->status === 'cancelled'
            && $task->updated_at
            && $task->updated_at->between($start, $end))->count();
        $open = $rows->whereIn('status', ['todo', 'in_progress'])->count();
        $overdue = $rows
            ->filter(fn (ProjectTask $task) => in_array($task->status, ['todo', 'in_progress'], true)
                && $task->due_date
                && $task->due_date->toDateString() < $today)
            ->count();
        $touched = $rows->filter(fn (ProjectTask $task) => ($task->created_at && $task->created_at->between($start, $end))
            || ($task->updated_at && $task->updated_at->between($start, $end)))->count();

        $actionable = $done + $open;
        $completionRate = $actionable > 0 ? round(($done / $actionable) * 100, 1) : 0.0;

        return [
            'total' => $touched,
            'open' => $open,
            'done' => $done,
            'cancelled' => $cancelled,
            'overdue' => $overdue,
            'completion_rate' => $completionRate,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function goalStats(
        int $businessId,
        int $userId,
        string $periodType,
        Carbon $start,
        Carbon $end,
    ): array {
        $targets = PipelineBoardTarget::query()
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->where('scope', 'member')
            ->where('member_user_id', $userId)
            ->whereNull('parent_id')
            ->with(['board:id,name,workspace,code', 'member:id,name', 'allocations'])
            ->orderBy('period_end')
            ->get();

        if ($targets->isEmpty()) {
            return $this->emptyGoalStats();
        }

        $items = [];
        foreach ($targets as $target) {
            $board = $target->board;
            if (! $board) {
                continue;
            }

            $serialized = $this->progress->serializeTargetForHr(
                $target,
                $board,
                $start,
                $end,
                $periodType === 'custom' ? null : $periodType,
            );
            $slice = $serialized['period_slice'] ?? null;
            $expected = is_array($slice) && isset($slice['expected_value'])
                ? (float) $slice['expected_value']
                : (float) $serialized['target_value'];

            $items[] = [
                'id' => $serialized['id'],
                'title' => $serialized['title'],
                'type' => $serialized['type'],
                'board_id' => (int) $board->id,
                'board_code' => $board->code,
                'board_name' => $board->name,
                'workspace' => $board->workspace,
                'metric_key' => $serialized['metric_key'],
                'target_value' => (float) $serialized['target_value'],
                'expected_value' => round($expected, 4),
                'actual_value' => (float) $serialized['actual_value'],
                'unit' => $serialized['unit'],
                'progress_percent' => $serialized['progress_percent'],
                'pace_status' => $serialized['pace_status'],
                'period_start' => is_array($slice)
                    ? ($slice['period_start'] ?? $serialized['period_start'])
                    : $serialized['period_start'],
                'period_end' => is_array($slice)
                    ? ($slice['period_end'] ?? $serialized['period_end'])
                    : $serialized['period_end'],
                'view_period_type' => is_array($slice)
                    ? ($slice['view_period_type'] ?? $periodType)
                    : $periodType,
                'period_slice' => $slice,
            ];
        }

        $paceCounts = [
            'achieved' => 0,
            'on_track' => 0,
            'at_risk' => 0,
            'behind' => 0,
        ];
        foreach ($items as $item) {
            $pace = $item['pace_status'];
            if (isset($paceCounts[$pace])) {
                $paceCounts[$pace]++;
            }
        }

        $avg = count($items) > 0
            ? round(collect($items)->avg('progress_percent') ?? 0, 1)
            : 0.0;

        return [
            'total' => count($items),
            'average_progress_percent' => $avg,
            'on_track_count' => $paceCounts['achieved'] + $paceCounts['on_track'],
            'at_risk_count' => $paceCounts['at_risk'],
            'behind_count' => $paceCounts['behind'],
            'items' => $items,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentLeads(int $businessId, int $userId, Carbon $start, Carbon $end): array
    {
        $leadIds = $this->assignedLeadIds($businessId, $userId);
        if ($leadIds === []) {
            return [];
        }

        return PipelineLead::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $leadIds)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('updated_at', [$start, $end])
                    ->orWhereBetween('created_at', [$start, $end])
                    ->orWhereBetween('won_at', [$start, $end])
                    ->orWhereBetween('lost_at', [$start, $end]);
            })
            ->with(['board:id,name,workspace,code', 'stage:id,name'])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get()
            ->map(fn (PipelineLead $lead) => [
                'id' => (int) $lead->id,
                'title' => $lead->title,
                'status' => $lead->status,
                'due_date' => $lead->due_date?->toDateString(),
                'board_id' => $lead->board_id ? (int) $lead->board_id : null,
                'board_code' => $lead->board?->code,
                'board_name' => $lead->board?->name,
                'workspace' => $lead->board?->workspace,
                'stage_name' => $lead->stage?->name,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentProjectTasks(int $businessId, int $userId, Carbon $start, Carbon $end): array
    {
        return ProjectTask::query()
            ->where('assigned_to', $userId)
            ->whereHas('project', fn ($q) => $q->where('business_id', $businessId))
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('updated_at', [$start, $end])
                    ->orWhereBetween('created_at', [$start, $end]);
            })
            ->with(['project:id,name,business_id'])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get()
            ->map(fn (ProjectTask $task) => [
                'id' => (int) $task->id,
                'name' => $task->name,
                'status' => $task->status,
                'due_date' => $task->due_date?->toDateString(),
                'project_id' => (int) $task->project_id,
                'project_name' => $task->project?->name,
            ])
            ->all();
    }

    /**
     * @return list<int>
     */
    public function assignedLeadIds(int $businessId, int $userId): array
    {
        $primary = PipelineLead::query()
            ->where('business_id', $businessId)
            ->where('assigned_to', $userId)
            ->pluck('id');

        $multi = DB::table('pipeline_lead_assignees')
            ->join('pipeline_leads', 'pipeline_leads.id', '=', 'pipeline_lead_assignees.lead_id')
            ->where('pipeline_leads.business_id', $businessId)
            ->where('pipeline_lead_assignees.user_id', $userId)
            ->whereNull('pipeline_leads.deleted_at')
            ->pluck('pipeline_leads.id');

        return $primary->merge($multi)->unique()->values()->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array<string, int|float>
     */
    public function emptyLeadStats(): array
    {
        return [
            'total' => 0,
            'open' => 0,
            'won' => 0,
            'lost' => 0,
            'converted' => 0,
            'overdue' => 0,
            'win_rate' => 0.0,
        ];
    }

    /**
     * @return array<string, int|float>
     */
    public function emptyTaskStats(): array
    {
        return [
            'total' => 0,
            'open' => 0,
            'done' => 0,
            'cancelled' => 0,
            'overdue' => 0,
            'completion_rate' => 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyGoalStats(): array
    {
        return [
            'total' => 0,
            'average_progress_percent' => 0.0,
            'on_track_count' => 0,
            'at_risk_count' => 0,
            'behind_count' => 0,
            'items' => [],
        ];
    }
}