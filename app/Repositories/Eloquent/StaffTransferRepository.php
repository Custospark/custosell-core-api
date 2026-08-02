<?php

namespace App\Repositories\Eloquent;

use App\Models\StaffTransfer;
use App\Repositories\Contracts\StaffTransferRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class StaffTransferRepository implements StaffTransferRepositoryInterface
{
    public function all(int $businessId): Collection
    {
        return StaffTransfer::with([
            'user:id,name,email',
            'fromLocation:id,name',
            'toLocation:id,name',
            'transferredBy:id,name',
        ])
            ->where('business_id', $businessId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(int $id): ?StaffTransfer
    {
        return StaffTransfer::find($id);
    }

    public function findForBusiness(int $id, int $businessId): ?StaffTransfer
    {
        return StaffTransfer::with([
            'user:id,name,email',
            'fromLocation:id,name',
            'toLocation:id,name',
            'transferredBy:id,name',
            'approvedBy:id,name',
        ])
            ->where('business_id', $businessId)
            ->whereKey($id)
            ->first();
    }

    public function create(array $data): StaffTransfer
    {
        return StaffTransfer::create($data);
    }

    public function countByBusiness(int $businessId): int
    {
        return StaffTransfer::where('business_id', $businessId)->count();
    }
}
