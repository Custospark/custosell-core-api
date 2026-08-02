<?php

namespace App\Services\Contracts;

use App\Models\StaffTransfer;
use Illuminate\Database\Eloquent\Collection;

interface StaffTransferServiceInterface
{
    public function getAll(int $businessId): Collection;

    public function getById(int $id): ?StaffTransfer;

    public function getByIdForBusiness(int $id, int $businessId): ?StaffTransfer;

    public function transfer(int $businessId, int $actorId, array $data): StaffTransfer;

    public function countByBusiness(int $businessId): int;
}
