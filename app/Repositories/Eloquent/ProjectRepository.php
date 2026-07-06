<?php

namespace App\Repositories\Eloquent;

use App\Models\Project;
use App\Models\ProjectCostAllocation;
use App\Models\ProjectTask;
use App\Models\TimesheetEntry;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ProjectRepository implements ProjectRepositoryInterface
{
    public function all(int $businessId, array $filters = []): Collection
    {
        $query = Project::where('business_id', $businessId)
            ->with(['customer', 'manager', 'createdBy', 'tasks']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        return $query->orderByDesc('created_at')->get();
    }

    public function find(int $id): ?Project
    {
        return Project::with([
            'customer',
            'manager',
            'createdBy',
            'estimate',
            'tasks.assignedTo',
            'timesheetEntries.user',
            'costAllocations',
        ])->find($id);
    }

    public function create(array $data): Project
    {
        return Project::create($data);
    }

    public function update(Project $project, array $data): Project
    {
        $project->update($data);

        return $project->fresh();
    }

    public function delete(Project $project): bool
    {
        return $project->delete();
    }

    public function findTask(int $id): ?ProjectTask
    {
        return ProjectTask::with('assignedTo')->find($id);
    }

    public function createTask(array $data): ProjectTask
    {
        return ProjectTask::create($data);
    }

    public function updateTask(ProjectTask $task, array $data): ProjectTask
    {
        $task->update($data);

        return $task->fresh();
    }

    public function deleteTask(ProjectTask $task): bool
    {
        return $task->delete();
    }

    public function findTimesheet(int $id): ?TimesheetEntry
    {
        return TimesheetEntry::with(['user', 'projectTask'])->find($id);
    }

    public function createTimesheet(array $data): TimesheetEntry
    {
        return TimesheetEntry::create($data);
    }

    public function updateTimesheet(TimesheetEntry $entry, array $data): TimesheetEntry
    {
        $entry->update($data);

        return $entry->fresh();
    }

    public function deleteTimesheet(TimesheetEntry $entry): bool
    {
        return $entry->delete();
    }

    public function findAllocation(int $id): ?ProjectCostAllocation
    {
        return ProjectCostAllocation::find($id);
    }

    public function createAllocation(array $data): ProjectCostAllocation
    {
        return ProjectCostAllocation::create($data);
    }

    public function updateAllocation(ProjectCostAllocation $allocation, array $data): ProjectCostAllocation
    {
        $allocation->update($data);

        return $allocation->fresh();
    }

    public function deleteAllocation(ProjectCostAllocation $allocation): bool
    {
        return $allocation->delete();
    }
}
