<?php

namespace App\Repositories\Contracts;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Collection;

interface ShiftRepositoryInterface
{
    public function all(int $businessId): Collection;

    public function find(int $id): ?Shift;

    public function create(array $data): Shift;

    public function update(Shift $shift, array $data): Shift;

    public function delete(Shift $shift): bool;

    public function getActiveByUser(int $businessId, int $userId): ?Shift;

    public function getByDateRange(int $businessId, string $start, string $end): Collection;
}
