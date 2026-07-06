<?php

namespace App\Repositories\Contracts;

use App\Models\Project;
use App\Models\ProjectCostAllocation;
use App\Models\ProjectTask;
use App\Models\TimesheetEntry;
use Illuminate\Database\Eloquent\Collection;

interface ProjectRepositoryInterface
{
    public function all(int $businessId, array $filters = []): Collection;

    public function find(int $id): ?Project;

    public function create(array $data): Project;

    public function update(Project $project, array $data): Project;

    public function delete(Project $project): bool;

    public function findTask(int $id): ?ProjectTask;

    public function createTask(array $data): ProjectTask;

    public function updateTask(ProjectTask $task, array $data): ProjectTask;

    public function deleteTask(ProjectTask $task): bool;

    public function findTimesheet(int $id): ?TimesheetEntry;

    public function createTimesheet(array $data): TimesheetEntry;

    public function updateTimesheet(TimesheetEntry $entry, array $data): TimesheetEntry;

    public function deleteTimesheet(TimesheetEntry $entry): bool;

    public function findAllocation(int $id): ?ProjectCostAllocation;

    public function createAllocation(array $data): ProjectCostAllocation;

    public function updateAllocation(ProjectCostAllocation $allocation, array $data): ProjectCostAllocation;

    public function deleteAllocation(ProjectCostAllocation $allocation): bool;
}
