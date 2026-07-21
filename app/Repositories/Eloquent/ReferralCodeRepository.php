<?php

namespace App\Repositories\Eloquent;

use App\Models\ReferralCode;
use App\Repositories\Contracts\ReferralCodeRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ReferralCodeRepository implements ReferralCodeRepositoryInterface
{
    public function all(): Collection
    {
        return ReferralCode::all();
    }

    public function find(int $id): ?ReferralCode
    {
        return ReferralCode::find($id);
    }

    public function findByCode(string $code): ?ReferralCode
    {
        return ReferralCode::where('code', $code)->first();
    }

    public function create(array $data): ReferralCode
    {
        return ReferralCode::create($data);
    }

    public function update(ReferralCode $referralCode, array $data): ReferralCode
    {
        $referralCode->update($data);
        return $referralCode->fresh();
    }

    public function delete(ReferralCode $referralCode): bool
    {
        return $referralCode->delete();
    }

    public function getActive(): Collection
    {
        return ReferralCode::active()->get();
    }

    public function findValidByCode(string $code): ?ReferralCode
    {
        return ReferralCode::where('code', $code)->active()->valid()->first();
    }
}
