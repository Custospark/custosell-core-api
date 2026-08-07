<?php

namespace App\Services\Contracts;

use App\Models\PersonalBudget;
use Illuminate\Database\Eloquent\Collection;

interface PersonalBudgetServiceInterface
{
    public function getAll(int $businessId, array $filters = []): array;

    public function getById(int $id): ?PersonalBudget;

    public function create(int $businessId, int $userId, array $data): PersonalBudget;

    public function update(int $id, array $data): PersonalBudget;

    public function delete(int $id): bool;
}