<?php

namespace App\Services\Contracts;

use App\Models\Project;
use App\Models\ProjectCostAllocation;
use App\Models\ProjectTask;
use App\Models\TimesheetEntry;
use Illuminate\Database\Eloquent\Collection;

interface ProjectServiceInterface
{
    public function getAll(int $businessId, array $filters = []): Collection;

    public function getById(int $id): ?Project;

    public function create(int $businessId, int $userId, array $data): Project;

    public function update(int $id, array $data): Project;

    public function delete(int $id): bool;

    public function createTask(int $projectId, array $data): ProjectTask;

    public function updateTask(int $taskId, array $data): ProjectTask;

    public function deleteTask(int $taskId): bool;

    public function createTimesheet(int $businessId, int $userId, int $projectId, array $data): TimesheetEntry;

    public function updateTimesheet(int $entryId, array $data): TimesheetEntry;

    public function deleteTimesheet(int $entryId): bool;

    public function createAllocation(int $businessId, int $userId, int $projectId, array $data): ProjectCostAllocation;

    public function updateAllocation(int $allocationId, array $data): ProjectCostAllocation;

    public function deleteAllocation(int $allocationId): bool;

    public function budgetSummary(int $projectId): array;

    public function profitability(int $projectId): array;

    public function recalculateActuals(int $projectId): Project;
}
