<?php

namespace App\Repositories\Eloquent;

use App\Models\ChartOfAccount;
use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ChartOfAccountRepository implements ChartOfAccountRepositoryInterface
{
    public function all(int $businessId, array $filters = []): LengthAwarePaginator
    {
        $query = ChartOfAccount::where('business_id', $businessId)
            ->with(['accountType', 'parent']);

        if (!empty($filters['type_id'])) {
            $query->where('type_id', $filters['type_id']);
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('code')
            ->paginate($filters['per_page'] ?? 200);
    }

    public function find(int $id): ?ChartOfAccount
    {
        return ChartOfAccount::with(['accountType', 'parent', 'children'])->find($id);
    }

    public function findByCode(int $businessId, string $code): ?ChartOfAccount
    {
        return ChartOfAccount::where('business_id', $businessId)
            ->where('code', $code)
            ->first();
    }

    public function create(array $data): ChartOfAccount
    {
        return ChartOfAccount::create($data);
    }

    public function update(ChartOfAccount $account, array $data): ChartOfAccount
    {
        $account->update($data);
        return $account->fresh();
    }

    public function delete(ChartOfAccount $account): bool
    {
        return $account->delete();
    }

    public function getTree(int $businessId): Collection
    {
        return ChartOfAccount::where('business_id', $businessId)
            ->whereNull('parent_id')
            ->with(['children' => function ($query) {
                $query->with(['children']);
            }, 'accountType'])
            ->orderBy('code')
            ->get();
    }

    public function getChildren(int $accountId): Collection
    {
        return ChartOfAccount::where('parent_id', $accountId)
            ->with(['accountType'])
            ->orderBy('code')
            ->get();
    }

    public function getActiveAccounts(int $businessId): Collection
    {
        return ChartOfAccount::where('business_id', $businessId)
            ->where('is_active', true)
            ->with(['accountType'])
            ->orderBy('code')
            ->get();
    }
}
