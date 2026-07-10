<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\PipelineBoardTarget;
use App\Models\PipelineLead;
use App\Models\ProjectTask;
use App\Services\Pipeline\PipelineBoardProgressService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Evaluates whether linked staff are meeting Pipeline/Projects goals
 * from assigned leads/cards, project tasks, and board targets.
 */
class HrPerformanceService
{
    public function __construct(
        protected HrEmployeeService $employees,
        protected HrTalentService $talent,
        protected PipelineBoardProgressService $progress,
    ) {}

    /**
     * Roster summaries for full HR, or a single self row for limited users.
     *
     * @return list<array<string, mixed>>
     */
    public function roster(
        int $businessId,
        int $actorUserId,
        bool $fullHr,
        string $periodType = 'month',
        ?string $from = null,
        ?string $to = null,
    ): array {
        [$start, $end] = $this->progress->resolvePeriod($periodType, $from, $to);

        $query = HrEmployee::query()
            ->where('business_id', $businessId)
            ->whereNotNull('user_id')
            ->with('user:id,name,email,avatar')
            ->orderBy('first_name')
            ->orderBy('last_name');

        if (! $fullHr) {
            $query->where('user_id', $actorUserId);
        }

        return $query->get()
            ->map(fn (HrEmployee $employee) => $this->summarizeEmployee(
                $businessId,
                $employee,
                $periodType,
                $start,
                $end,
            ))
            ->values()
            ->all();
    }

    /**
     * Full evaluation snapshot for one employee.
     *
     * @return array<string, mixed>
     */
    public function evaluateEmployee(
        int $businessId,
        int $employeeId,
        int $actorUserId,
        bool $fullHr,
        string $periodType = 'month',
        ?string $from = null,
        ?string $to = null,
    ): array {
        $employee = $this->employees->findOrFail($businessId, $employeeId);
        $this->assertCanViewEmployee($employee, $actorUserId, $fullHr);
        [$start, $end] = $this->progress->resolvePeriod($periodType, $from, $to);

        return $this->buildSnapshot($businessId, $employee, $periodType, $start, $end);
    }

    /**
     * Resolve evaluation by staff user id (Pipeline/Projects assignee deep-link).
     *
     * @return array<string, mixed>
     */
    public function evaluateByUserId(
        int $businessId,
        int $userId,
        int $actorUserId,
        bool $fullHr,
        string $periodType = 'month',
        ?string $from = null,
        ?string $to = null,
    ): array {
        $employee = HrEmployee::query()
            ->where('business_id', $businessId)
            ->where('user_id', $userId)
            ->first();

        if (! $employee) {
            throw new HttpException(404, 'No HR employee is linked to that staff account.');
        }

        return $this->evaluateEmployee(
            $businessId,
            (int) $employee->id,
            $actorUserId,
            $fullHr,
            $periodType,
            $from,
            $to,
        );
    }

    /**
     * Seed a draft performance review from the live work snapshot.
     *
     * @return array{review: mixed, snapshot: array<string, mixed>}
     */
    public function seedReviewFromSnapshot(
        int $businessId,
        int $employeeId,
        int $actorUserId,
        string $periodType = 'month',
        ?string $from = null,
        ?string $to = null,
    ): array {
        $employee = $this->employees->findOrFail($businessId, $employeeId);
        [$start, $end] = $this->progress->resolvePeriod($periodType, $from, $to);
        $snapshot = $this->buildSnapshot($businessId, $employee, $periodType, $start, $end);

        if ($snapshot['link_status'] === 'unlinked') {
            throw new HttpException(422, 'Link an app login to this employee before seeding a review from Pipeline/Projects work.');
        }

        $periodLabel = sprintf(
            'Work performance %s (%s – %s)',
            $periodType,
            $start->toDateString(),
            $end->toDateString(),
        );

        $review = $this->talent->createReview($businessId, [
            'employee_id' => $employeeId,
            'period_label' => $periodLabel,
            'status' => 'draft',
            'rating' => $this->suggestedRating($snapshot['verdict']),
            'strengths' => $this->suggestedStrengths($snapshot),
            'improvements' => $this->suggestedImprovements($snapshot),
            'notes' => $this->suggestedNotes($snapshot),
        ], $actorUserId);

        return [
            'review' => $review,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function summarizeEmployee(
        int $businessId,
        HrEmployee $employee,
        string $periodType,
        Carbon $start,
        Carbon $end,
    ): array {
        $snapshot = $this->buildSnapshot($businessId, $employee, $periodType, $start, $end);

        return [
            'employee_id' => $snapshot['employee']['id'],
            'employee' => $snapshot['employee'],
            'user_id' => $snapshot['user_id'],
            'link_status' => $snapshot['link_status'],
            'verdict' => $snapshot['verdict'],
            'verdict_label' => $snapshot['verdict_label'],
            'goal_progress_avg' => $snapshot['goals']['average_progress_percent'],
            'goals_on_track' => $snapshot['goals']['on_track_count'],
            'goals_total' => $snapshot['goals']['total'],
            'leads_open' => $snapshot['leads']['open'],
            'leads_overdue' => $snapshot['leads']['overdue'],
            'tasks_open' => $snapshot['project_tasks']['open'],
            'tasks_overdue' => $snapshot['project_tasks']['overdue'],
            'tasks_done' => $snapshot['project_tasks']['done'],
            'period' => $snapshot['period'],
            'evaluated_at' => $snapshot['evaluated_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildSnapshot(
        int $businessId,
        HrEmployee $employee,
        string $periodType,
        Carbon $start,
        Carbon $end,
    ): array {
        $userId = $employee->user_id ? (int) $employee->user_id : null;
        $periodPayload = [
            'type' => $periodType,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
        ];
        $employeePayload = [
            'id' => (int) $employee->id,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'employee_number' => $employee->employee_number,
            'status' => $employee->status,
            'user_id' => $userId,
        ];

        if ($userId === null) {
            return [
                'employee' => $employeePayload,
                'user_id' => null,
                'link_status' => 'unlinked',
                'verdict' => 'unlinked',
                'verdict_label' => 'No app login linked',
                'period' => $periodPayload,
                'leads' => $this->emptyLeadStats(),
                'project_tasks' => $this->emptyTaskStats(),
                'goals' => $this->emptyGoalStats(),
                'recent_leads' => [],
                'recent_tasks' => [],
                'evaluated_at' => now()->toIso8601String(),
            ];
        }

        $leads = $this->leadStats($businessId, $userId, $start, $end);
        $tasks = $this->projectTaskStats($businessId, $userId, $start, $end);
        $goals = $this->goalStats($businessId, $userId, $periodType, $start, $end);
        $verdict = $this->resolveVerdict($goals, $leads, $tasks);

        return [
            'employee' => $employeePayload,
            'user_id' => $userId,
            'link_status' => 'linked',
            'verdict' => $verdict,
            'verdict_label' => $this->verdictLabel($verdict),
            'period' => $periodPayload,
            'leads' => $leads,
            'project_tasks' => $tasks,
            'goals' => $goals,
            'recent_leads' => $this->recentLeads($businessId, $userId, $start, $end),
            'recent_tasks' => $this->recentProjectTasks($businessId, $userId, $start, $end),
            'evaluated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    protected function leadStats(int $businessId, int $userId, Carbon $start, Carbon $end): array
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
    protected function projectTaskStats(int $businessId, int $userId, Carbon $start, Carbon $end): array
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
    protected function goalStats(
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
            ->with(['board:id,name,workspace', 'member:id,name'])
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
            $items[] = [
                'id' => $serialized['id'],
                'title' => $serialized['title'],
                'type' => $serialized['type'],
                'board_id' => (int) $board->id,
                'board_name' => $board->name,
                'workspace' => $board->workspace,
                'metric_key' => $serialized['metric_key'],
                'target_value' => $serialized['target_value'],
                'actual_value' => $serialized['actual_value'],
                'unit' => $serialized['unit'],
                'progress_percent' => $serialized['progress_percent'],
                'pace_status' => $serialized['pace_status'],
                'period_start' => $serialized['period_start'],
                'period_end' => $serialized['period_end'],
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
    protected function recentLeads(int $businessId, int $userId, Carbon $start, Carbon $end): array
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
            ->with(['board:id,name,workspace', 'stage:id,name'])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get()
            ->map(fn (PipelineLead $lead) => [
                'id' => (int) $lead->id,
                'title' => $lead->title,
                'status' => $lead->status,
                'due_date' => $lead->due_date?->toDateString(),
                'board_id' => $lead->board_id ? (int) $lead->board_id : null,
                'board_name' => $lead->board?->name,
                'workspace' => $lead->board?->workspace,
                'stage_name' => $lead->stage?->name,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function recentProjectTasks(int $businessId, int $userId, Carbon $start, Carbon $end): array
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
    protected function assignedLeadIds(int $businessId, int $userId): array
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
     * @param  array<string, mixed>  $goals
     * @param  array<string, int|float>  $leads
     * @param  array<string, int|float>  $tasks
     */
    protected function resolveVerdict(array $goals, array $leads, array $tasks): string
    {
        $hasGoals = ($goals['total'] ?? 0) > 0;
        $hasWork = ($leads['total'] ?? 0) > 0 || ($tasks['total'] ?? 0) > 0;

        if (! $hasGoals && ! $hasWork) {
            return 'no_data';
        }

        if ($hasGoals) {
            if (($goals['behind_count'] ?? 0) > 0) {
                return 'behind';
            }
            if (($goals['at_risk_count'] ?? 0) > 0) {
                return 'at_risk';
            }
            if (($goals['on_track_count'] ?? 0) > 0) {
                return 'on_track';
            }
        }

        // Fallback when no member goals: use overdue + completion signals from work items.
        $overdue = (int) ($leads['overdue'] ?? 0) + (int) ($tasks['overdue'] ?? 0);
        if ($overdue >= 3) {
            return 'behind';
        }
        if ($overdue >= 1) {
            return 'at_risk';
        }

        $completion = (float) ($tasks['completion_rate'] ?? 0);
        $winRate = (float) ($leads['win_rate'] ?? 0);
        if ($completion >= 70 || $winRate >= 40 || ((int) ($tasks['done'] ?? 0) + (int) ($leads['won'] ?? 0)) > 0) {
            return 'on_track';
        }

        return $hasWork ? 'at_risk' : 'no_data';
    }

    protected function verdictLabel(string $verdict): string
    {
        return match ($verdict) {
            'on_track' => 'Meeting goals',
            'at_risk' => 'At risk',
            'behind' => 'Behind goals',
            'unlinked' => 'No app login linked',
            default => 'No work data yet',
        };
    }

    protected function suggestedRating(string $verdict): ?float
    {
        return match ($verdict) {
            'on_track' => 4.0,
            'at_risk' => 3.0,
            'behind' => 2.0,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function suggestedStrengths(array $snapshot): ?string
    {
        $parts = [];
        $goalsOnTrack = (int) ($snapshot['goals']['on_track_count'] ?? 0);
        $won = (int) ($snapshot['leads']['won'] ?? 0);
        $done = (int) ($snapshot['project_tasks']['done'] ?? 0);

        if ($goalsOnTrack > 0) {
            $parts[] = sprintf('%d board goal(s) on track or achieved.', $goalsOnTrack);
        }
        if ($won > 0) {
            $parts[] = sprintf('%d won pipeline card(s).', $won);
        }
        if ($done > 0) {
            $parts[] = sprintf('%d completed project task(s).', $done);
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function suggestedImprovements(array $snapshot): ?string
    {
        $parts = [];
        $behind = (int) ($snapshot['goals']['behind_count'] ?? 0);
        $atRisk = (int) ($snapshot['goals']['at_risk_count'] ?? 0);
        $leadOverdue = (int) ($snapshot['leads']['overdue'] ?? 0);
        $taskOverdue = (int) ($snapshot['project_tasks']['overdue'] ?? 0);

        if ($behind > 0) {
            $parts[] = sprintf('%d goal(s) behind pace — review targets and blockers.', $behind);
        }
        if ($atRisk > 0) {
            $parts[] = sprintf('%d goal(s) at risk — tighten weekly follow-up.', $atRisk);
        }
        if ($leadOverdue > 0) {
            $parts[] = sprintf('%d overdue pipeline card(s).', $leadOverdue);
        }
        if ($taskOverdue > 0) {
            $parts[] = sprintf('%d overdue project task(s).', $taskOverdue);
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function suggestedNotes(array $snapshot): string
    {
        return sprintf(
            "Auto-seeded from Pipeline/Projects work on %s.\nVerdict: %s.\nGoals avg progress: %s%%.\nLeads open/won/overdue: %d/%d/%d.\nProject tasks open/done/overdue: %d/%d/%d.",
            Carbon::parse($snapshot['evaluated_at'])->toDateString(),
            $snapshot['verdict_label'],
            (string) ($snapshot['goals']['average_progress_percent'] ?? 0),
            (int) ($snapshot['leads']['open'] ?? 0),
            (int) ($snapshot['leads']['won'] ?? 0),
            (int) ($snapshot['leads']['overdue'] ?? 0),
            (int) ($snapshot['project_tasks']['open'] ?? 0),
            (int) ($snapshot['project_tasks']['done'] ?? 0),
            (int) ($snapshot['project_tasks']['overdue'] ?? 0),
        );
    }

    /**
     * @return array<string, int|float>
     */
    protected function emptyLeadStats(): array
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
    protected function emptyTaskStats(): array
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
    protected function emptyGoalStats(): array
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

    protected function assertCanViewEmployee(HrEmployee $employee, int $actorUserId, bool $fullHr): void
    {
        if ($fullHr) {
            return;
        }

        if ((int) $employee->user_id !== $actorUserId) {
            throw new HttpException(403, 'You can only view your own work performance.');
        }
    }
}
