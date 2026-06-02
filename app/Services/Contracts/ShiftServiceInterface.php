<?php

namespace App\Services\Contracts;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Collection;

interface ShiftServiceInterface
{
    public function getAll(int $businessId): Collection;

    public function getById(int $id): ?Shift;

    public function create(int $businessId, int $userId, array $data): Shift;

    public function update(int $id, array $data): Shift;

    public function delete(int $id): bool;

    public function getActiveByUser(int $businessId, int $userId): ?Shift;

    public function getByDateRange(int $businessId, string $start, string $end): Collection;
}
