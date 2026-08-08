<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\Hr\HrEmployee;
use App\Services\Pipeline\PipelineBoardProgressService;
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Orchestrates work-performance evaluation for HR employees from
 * Pipeline/Projects activity: roster, snapshots, and review seeding.
 *
 * Heavy lifting is delegated to {@see HrPerformanceMetrics} (aggregation)
 * and {@see HrPerformanceInsights} (verdict + suggested review text).
 */
class HrPerformanceService
{
    public function __construct(
        protected HrEmployeeService $employees,
        protected HrTalentService $talent,
        protected PipelineBoardProgressService $progress,
        protected HrPerformanceMetrics $metrics,
        protected HrPerformanceInsights $insights,
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
            'rating' => $this->insights->suggestedRating($snapshot['verdict']),
            'strengths' => $this->insights->suggestedStrengths($snapshot),
            'improvements' => $this->insights->suggestedImprovements($snapshot),
            'notes' => $this->insights->suggestedNotes($snapshot),
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
                'leads' => $this->metrics->emptyLeadStats(),
                'project_tasks' => $this->metrics->emptyTaskStats(),
                'goals' => $this->metrics->emptyGoalStats(),
                'recent_leads' => [],
                'recent_tasks' => [],
                'evaluated_at' => now()->toIso8601String(),
            ];
        }

        $leads = $this->metrics->leadStats($businessId, $userId, $start, $end);
        $tasks = $this->metrics->projectTaskStats($businessId, $userId, $start, $end);
        $goals = $this->metrics->goalStats($businessId, $userId, $periodType, $start, $end);
        $verdict = $this->insights->resolveVerdict($goals, $leads, $tasks);

        return [
            'employee' => $employeePayload,
            'user_id' => $userId,
            'link_status' => 'linked',
            'verdict' => $verdict,
            'verdict_label' => $this->insights->verdictLabel($verdict),
            'period' => $periodPayload,
            'leads' => $leads,
            'project_tasks' => $tasks,
            'goals' => $goals,
            'recent_leads' => $this->metrics->recentLeads($businessId, $userId, $start, $end),
            'recent_tasks' => $this->metrics->recentProjectTasks($businessId, $userId, $start, $end),
            'evaluated_at' => now()->toIso8601String(),
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