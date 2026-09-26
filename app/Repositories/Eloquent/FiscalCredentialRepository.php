<?php

namespace App\Repositories\Eloquent;

use App\Models\FiscalCredential;
use App\Repositories\Contracts\FiscalCredentialRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class FiscalCredentialRepository implements FiscalCredentialRepositoryInterface
{
    public function all(int $businessId): Collection
    {
        return FiscalCredential::where('business_id', $businessId)
            ->with('location')
            ->orderBy('location_id')
            ->get();
    }

    public function find(int $id): ?FiscalCredential
    {
        return FiscalCredential::with('location')->find($id);
    }

    public function findForScope(int $businessId, ?int $locationId): ?FiscalCredential
    {
        if ($locationId) {
            $branch = FiscalCredential::where('business_id', $businessId)
                ->where('location_id', $locationId)
                ->where('is_active', true)
                ->first();
            if ($branch) {
                return $branch;
            }
        }

        return FiscalCredential::where('business_id', $businessId)
            ->whereNull('location_id')
            ->where('is_active', true)
            ->first();
    }

    public function create(array $data): FiscalCredential
    {
        return FiscalCredential::create($data);
    }

    public function update(FiscalCredential $credential, array $data): FiscalCredential
    {
        $credential->update($data);
        return $credential->fresh();
    }

    public function delete(FiscalCredential $credential): bool
    {
        return $credential->delete();
    }
}
