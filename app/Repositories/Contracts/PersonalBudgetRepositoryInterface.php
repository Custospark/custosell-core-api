<?php

namespace App\Repositories\Contracts;

use App\Models\PersonalBudget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PersonalBudgetRepositoryInterface
{
    public function all(int $businessId, array $filters = []): Collection;

    public function find(int $id): ?PersonalBudget;

    public function create(array $data): PersonalBudget;

    public function update(PersonalBudget $budget, array $data): PersonalBudget;

    public function delete(PersonalBudget $budget): bool;

    public function summarise(int $businessId, array $filters = []): array;
}