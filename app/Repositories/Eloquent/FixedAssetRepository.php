<?php

namespace App\Repositories\Eloquent;

use App\Models\FixedAsset;
use App\Repositories\Contracts\FixedAssetRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class FixedAssetRepository implements FixedAssetRepositoryInterface
{
    public function all(int $businessId, array $filters = []): LengthAwarePaginator
    {
        $query = FixedAsset::where('business_id', $businessId)
            ->with(['chartOfAccount', 'assignedEmployee']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (!empty($filters['assigned_employee_id'])) {
            $query->where('assigned_employee_id', $filters['assigned_employee_id']);
        }
        if (!empty($filters['unassigned'])) {
            $query->whereNull('assigned_employee_id');
        }
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('asset_tag', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('name')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function find(int $id): ?FixedAsset
    {
        return FixedAsset::with([
            'chartOfAccount',
            'depreciationEntries',
            'assignedEmployee',
            'assignments.fromEmployee',
            'assignments.toEmployee',
            'assignments.performedBy',
        ])->find($id);
    }

    public function create(array $data): FixedAsset
    {
        return FixedAsset::create($data);
    }

    public function update(FixedAsset $asset, array $data): FixedAsset
    {
        $asset->update($data);
        return $asset->fresh([
            'chartOfAccount',
            'assignedEmployee',
            'assignments.fromEmployee',
            'assignments.toEmployee',
            'assignments.performedBy',
        ]);
    }

    public function delete(FixedAsset $asset): bool
    {
        return $asset->delete();
    }

    public function getDueForDepreciation(int $businessId, int $periodId): Collection
    {
        return FixedAsset::where('business_id', $businessId)
            ->where('status', 'active')
            ->whereColumn('book_value', '>', 'salvage_value')
            ->whereDoesntHave('depreciationEntries', function ($query) use ($periodId) {
                $query->where('period_id', $periodId);
            })
            ->get();
    }
}
