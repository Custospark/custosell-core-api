<?php

namespace App\Repositories\Eloquent;

use App\Models\SalesRep;
use App\Repositories\Contracts\SalesRepRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class SalesRepRepository implements SalesRepRepositoryInterface
{
    public function all(): Collection
    {
        return SalesRep::all();
    }

    public function find(int $id): ?SalesRep
    {
        return SalesRep::find($id);
    }

    public function findByUser(int $userId): ?SalesRep
    {
        return SalesRep::where('user_id', $userId)->first();
    }

    public function findByReferralCode(int $codeId): ?SalesRep
    {
        return SalesRep::where('referral_code_id', $codeId)->first();
    }

    public function create(array $data): SalesRep
    {
        return SalesRep::create($data);
    }

    public function update(SalesRep $salesRep, array $data): SalesRep
    {
        $salesRep->update($data);
        return $salesRep->fresh();
    }

    public function delete(SalesRep $salesRep): bool
    {
        return $salesRep->delete();
    }

    public function getActive(): Collection
    {
        return SalesRep::active()->get();
    }
}
