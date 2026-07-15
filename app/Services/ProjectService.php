<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectCostAllocation;
use App\Models\ProjectTask;
use App\Models\TimesheetEntry;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\Contracts\ProjectServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ProjectService implements ProjectServiceInterface
{
    public function __construct(
        protected ProjectRepositoryInterface $projectRepository,
    ) {}

    public function getAll(int $businessId, array $filters = []): Collection
    {
        return $this->projectRepository->all($businessId, $filters);
    }

    public function getById(int $id): ?Project
    {
        return $this->projectRepository->find($id);
    }

    public function create(int $businessId, int $userId, array $data): Project
    {
        return DB::transaction(function () use ($businessId, $userId, $data) {
            $business = Business::findOrFail($businessId);
            $projectNumber = DocumentNumberGenerator::projectNumber($business, Project::class, 'project_number');

            $project = $this->projectRepository->create([
                'business_id' => $businessId,
                'customer_id' => $data['customer_id'] ?? null,
                'estimate_id' => $data['estimate_id'] ?? null,
                'pipeline_lead_id' => $data['pipeline_lead_id'] ?? null,
                'project_number' => $projectNumber,
                'name' => $data['name'],
                'status' => $data['status'] ?? 'planning',
                'currency' => $data['currency'] ?? $business->currency ?? 'UGX',
                'budget_revenue' => $data['budget_revenue'] ?? 0,
                'budget_cost' => $data['budget_cost'] ?? 0,
                'actual_cost' => 0,
                'actual_revenue' => 0,
                'start_date' => $data['start_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'description' => $data['description'] ?? null,
                'manager_id' => $data['manager_id'] ?? null,
                'created_by' => $userId,
                'is_personal' => (bool) ($data['is_personal'] ?? false),
            ]);

            \App\Models\ProjectMember::query()->updateOrCreate(
                ['project_id' => $project->id, 'user_id' => $userId],
                ['role' => 'manager'],
            );

            return $project->load(['customer', 'manager', 'createdBy']);
        });
    }

    public function update(int $id, array $data): Project
    {
        $project = $this->projectRepository->find($id);
        if (!$project) {
            throw new \RuntimeException('Project not found');
        }

        return $this->projectRepository->update($project, $data)
            ->load(['customer', 'manager', 'tasks']);
    }

    public function delete(int $id): bool
    {
        $project = $this->projectRepository->find($id);
        if (!$project) {
            throw new \RuntimeException('Project not found');
        }

        return $this->projectRepository->delete($project);
    }

    public function createTask(int $projectId, array $data): ProjectTask
    {
        $project = $this->projectRepository->find($projectId);
        if (!$project) {
            throw new \RuntimeException('Project not found');
        }

        return $this->projectRepository->createTask(array_merge($data, [
            'project_id' => $projectId,
        ]))->load('assignedTo');
    }

    public function updateTask(int $taskId, array $data): ProjectTask
    {
        $task = $this->projectRepository->findTask($taskId);
        if (!$task) {
            throw new \RuntimeException('Project task not found');
        }

        return $this->projectRepository->updateTask($task, $data)->load('assignedTo');
    }

    public function deleteTask(int $taskId): bool
    {
        $task = $this->projectRepository->findTask($taskId);
        if (!$task) {
            throw new \RuntimeException('Project task not found');
        }

        return $this->projectRepository->deleteTask($task);
    }

    public function createTimesheet(int $businessId, int $userId, int $projectId, array $data): TimesheetEntry
    {
        $project = $this->projectRepository->find($projectId);
        if (!$project) {
            throw new \RuntimeException('Project not found');
        }

        $hours = (float) ($data['hours'] ?? 0);
        $hourlyRate = (float) ($data['hourly_rate'] ?? 0);
        $totalCost = round($hours * $hourlyRate, 2);

        $entry = $this->projectRepository->createTimesheet([
            'business_id' => $businessId,
            'project_id' => $projectId,
            'project_task_id' => $data['project_task_id'] ?? null,
            'user_id' => $data['user_id'] ?? $userId,
            'entry_date' => $data['entry_date'],
            'hours' => $hours,
            'hourly_rate' => $hourlyRate,
            'total_cost' => $totalCost,
            'notes' => $data['notes'] ?? null,
            'is_billable' => $data['is_billable'] ?? true,
            'status' => $data['status'] ?? 'approved',
            'created_by' => $userId,
        ]);

        $this->recalculateActuals($projectId);

        return $entry->load(['user', 'projectTask']);
    }

    public function updateTimesheet(int $entryId, array $data): TimesheetEntry
    {
        $entry = $this->projectRepository->findTimesheet($entryId);
        if (!$entry) {
            throw new \RuntimeException('Timesheet entry not found');
        }

        if (isset($data['hours']) || isset($data['hourly_rate'])) {
            $hours = (float) ($data['hours'] ?? $entry->hours);
            $hourlyRate = (float) ($data['hourly_rate'] ?? $entry->hourly_rate);
            $data['total_cost'] = round($hours * $hourlyRate, 2);
        }

        $entry = $this->projectRepository->updateTimesheet($entry, $data);
        $this->recalculateActuals($entry->project_id);

        return $entry->load(['user', 'projectTask']);
    }

    public function deleteTimesheet(int $entryId): bool
    {
        $entry = $this->projectRepository->findTimesheet($entryId);
        if (!$entry) {
            throw new \RuntimeException('Timesheet entry not found');
        }

        $projectId = $entry->project_id;
        $deleted = $this->projectRepository->deleteTimesheet($entry);
        $this->recalculateActuals($projectId);

        return $deleted;
    }

    public function createAllocation(int $businessId, int $userId, int $projectId, array $data): ProjectCostAllocation
    {
        $project = $this->projectRepository->find($projectId);
        if (!$project) {
            throw new \RuntimeException('Project not found');
        }

        $allocation = $this->projectRepository->createAllocation([
            'business_id' => $businessId,
            'project_id' => $projectId,
            'allocation_type' => $data['allocation_type'],
            'description' => $data['description'],
            'amount' => $data['amount'],
            'basis' => $data['basis'] ?? 'fixed',
            'basis_value' => $data['basis_value'] ?? 0,
            'allocation_date' => $data['allocation_date'],
            'expense_id' => $data['expense_id'] ?? null,
            'created_by' => $userId,
        ]);

        $this->recalculateActuals($projectId);

        return $allocation;
    }

    public function updateAllocation(int $allocationId, array $data): ProjectCostAllocation
    {
        $allocation = $this->projectRepository->findAllocation($allocationId);
        if (!$allocation) {
            throw new \RuntimeException('Cost allocation not found');
        }

        $allocation = $this->projectRepository->updateAllocation($allocation, $data);
        $this->recalculateActuals($allocation->project_id);

        return $allocation;
    }

    public function deleteAllocation(int $allocationId): bool
    {
        $allocation = $this->projectRepository->findAllocation($allocationId);
        if (!$allocation) {
            throw new \RuntimeException('Cost allocation not found');
        }

        $projectId = $allocation->project_id;
        $deleted = $this->projectRepository->deleteAllocation($allocation);
        $this->recalculateActuals($projectId);

        return $deleted;
    }

    public function budgetSummary(int $projectId): array
    {
        $project = $this->projectRepository->find($projectId);
        if (!$project) {
            throw new \RuntimeException('Project not found');
        }

        $project = $this->recalculateActuals($projectId);

        $budgetRevenue = (float) $project->budget_revenue;
        $budgetCost = (float) $project->budget_cost;
        $actualCost = (float) $project->actual_cost;
        $actualRevenue = (float) $project->actual_revenue;

        return [
            'project_id' => $project->id,
            'budget_revenue' => $budgetRevenue,
            'budget_cost' => $budgetCost,
            'actual_revenue' => $actualRevenue,
            'actual_cost' => $actualCost,
            'revenue_variance' => round($actualRevenue - $budgetRevenue, 2),
            'cost_variance' => round($actualCost - $budgetCost, 2),
            'margin_budget' => $budgetRevenue > 0
                ? round((($budgetRevenue - $budgetCost) / $budgetRevenue) * 100, 2)
                : 0,
            'margin_actual' => $actualRevenue > 0
                ? round((($actualRevenue - $actualCost) / $actualRevenue) * 100, 2)
                : 0,
            'margin_percent_budget' => $budgetRevenue > 0
                ? round((($budgetRevenue - $budgetCost) / $budgetRevenue) * 100, 2)
                : 0,
            'margin_percent_actual' => $actualRevenue > 0
                ? round((($actualRevenue - $actualCost) / $actualRevenue) * 100, 2)
                : 0,
        ];
    }

    public function profitability(int $projectId): array
    {
        $summary = $this->budgetSummary($projectId);
        $project = $this->projectRepository->find($projectId);

        $grossProfit = round($summary['actual_revenue'] - $summary['actual_cost'], 2);
        $budgetProfit = round($summary['budget_revenue'] - $summary['budget_cost'], 2);

        $timesheetCost = (float) TimesheetEntry::query()
            ->where('project_id', $projectId)
            ->where('status', 'approved')
            ->sum('total_cost');

        $allocationCost = (float) ProjectCostAllocation::query()
            ->where('project_id', $projectId)
            ->sum('amount');

        return array_merge($summary, [
            'margin_percent' => $summary['margin_percent_actual'],
            'gross_profit' => $grossProfit,
            'budget_profit' => $budgetProfit,
            'profit_variance' => round($grossProfit - $budgetProfit, 2),
            'timesheet_cost' => round($timesheetCost, 2),
            'allocation_cost' => round($allocationCost, 2),
            'status' => $project?->status,
        ]);
    }

    public function recalculateActuals(int $projectId): Project
    {
        $project = $this->projectRepository->find($projectId);
        if (!$project) {
            throw new \RuntimeException('Project not found');
        }

        $timesheetCost = (float) TimesheetEntry::query()
            ->where('project_id', $projectId)
            ->where('status', 'approved')
            ->sum('total_cost');

        $allocationCost = (float) ProjectCostAllocation::query()
            ->where('project_id', $projectId)
            ->sum('amount');

        $billableRevenue = (float) TimesheetEntry::query()
            ->where('project_id', $projectId)
            ->where('status', 'approved')
            ->where('is_billable', true)
            ->selectRaw('SUM(hours * hourly_rate) as revenue')
            ->value('revenue');

        $invoiceRevenue = (float) Invoice::query()
            ->whereHas('estimate', fn($q) => $q->where('project_id', $projectId))
            ->sum('amount_paid');

        return $this->projectRepository->update($project, [
            'actual_cost' => round($timesheetCost + $allocationCost, 2),
            'actual_revenue' => round($billableRevenue + $invoiceRevenue, 2),
        ]);
    }

    public function getMemberProjects(int $businessId, int $userId, array $filters = []): Collection
    {
        return $this->projectRepository->forMember($businessId, $userId, $filters);
    }

    public function listMembers(int $projectId): Collection
    {
        $project = $this->projectRepository->find($projectId);
        if (!$project) {
            throw new \RuntimeException('Project not found');
        }

        return $project->members()->with('user')->orderBy('created_at')->get();
    }

    /** @param  array{user_id: int, role?: string}  $data */
    public function addMember(int $projectId, array $data, ?int $actorUserId = null): \App\Models\ProjectMember
    {
        $project = $this->projectRepository->find($projectId);
        if (!$project) {
            throw new \RuntimeException('Project not found');
        }

        $role = $data['role'] ?? 'contributor';
        if (!in_array($role, ['viewer', 'contributor', 'manager'], true)) {
            $role = 'contributor';
        }

        $member = \App\Models\ProjectMember::query()->updateOrCreate(
            ['project_id' => $projectId, 'user_id' => $data['user_id']],
            ['role' => $role],
        );

        $this->syncProjectBoardMember($project, (int) $data['user_id'], $role);

        if (!empty($data['send_notification']) && $actorUserId) {
            $recipient = \App\Models\User::find((int) $data['user_id']);
            $actor = \App\Models\User::find($actorUserId);
            if ($recipient && $actor) {
                $board = \App\Models\PipelineBoard::query()
                    ->where('project_id', $project->id)
                    ->first();
                if ($board) {
                    app(\App\Services\Pipeline\PipelineNotificationService::class)
                        ->notifyBoardMemberAdded($board, $actor, [$recipient], $role);
                }
            }
        }

        return $member->load('user');
    }

    public function updateMemberRole(int $projectId, int $userId, string $role): \App\Models\ProjectMember
    {
        $project = $this->projectRepository->find($projectId);
        if (!$project) {
            throw new \RuntimeException('Project not found');
        }

        if ((int) $userId === (int) $project->created_by) {
            throw new \RuntimeException('Project owner role cannot be changed.');
        }

        $member = \App\Models\ProjectMember::query()
            ->where('project_id', $projectId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $member->update(['role' => $role]);
        $this->syncProjectBoardMember($project, $userId, $role);

        return $member->fresh()->load('user');
    }

    public function removeMember(int $projectId, int $userId): void
    {
        $project = $this->projectRepository->find($projectId);
        if (!$project) {
            throw new \RuntimeException('Project not found');
        }

        if ((int) $userId === (int) $project->created_by) {
            throw new \RuntimeException('Project owner cannot be removed.');
        }

        \App\Models\ProjectMember::query()
            ->where('project_id', $projectId)
            ->where('user_id', $userId)
            ->delete();

        $board = \App\Models\PipelineBoard::query()
            ->where('project_id', $projectId)
            ->first();

        if ($board) {
            \App\Models\PipelineBoardMember::query()
                ->where('board_id', $board->id)
                ->where('user_id', $userId)
                ->delete();
        }
    }

    protected function syncProjectBoardMember(Project $project, int $userId, string $role): void
    {
        $board = \App\Models\PipelineBoard::query()
            ->where('project_id', $project->id)
            ->first();

        if (!$board) {
            return;
        }

        $boardRole = in_array($role, ['contributor', 'manager'], true) ? 'contributor' : 'viewer';

        \App\Models\PipelineBoardMember::query()->updateOrCreate(
            ['board_id' => $board->id, 'user_id' => $userId],
            ['role' => $boardRole],
        );
    }
}
