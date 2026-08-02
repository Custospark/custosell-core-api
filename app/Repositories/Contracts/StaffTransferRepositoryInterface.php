<?php

namespace App\Repositories\Contracts;

use App\Models\StaffTransfer;
use Illuminate\Database\Eloquent\Collection;

interface StaffTransferRepositoryInterface
{
    public function all(int $businessId): Collection;

    public function find(int $id): ?StaffTransfer;

    public function findForBusiness(int $id, int $businessId): ?StaffTransfer;

    public function create(array $data): StaffTransfer;

    public function countByBusiness(int $businessId): int;
}
