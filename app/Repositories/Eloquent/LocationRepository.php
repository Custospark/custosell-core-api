<?php

namespace App\Repositories\Eloquent;

use App\Models\Location;
use App\Repositories\Contracts\LocationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class LocationRepository implements LocationRepositoryInterface
{
    public function all(int $businessId): Collection
    {
        return Location::forBusiness($businessId)
            ->withCount('users')
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();
    }

    public function active(int $businessId): Collection
    {
        return Location::forBusiness($businessId)
            ->active()
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): ?Location
    {
        return Location::find($id);
    }

    public function default(int $businessId): ?Location
    {
        return Location::forBusiness($businessId)
            ->where('is_default', true)
            ->first();
    }

    public function create(array $data): Location
    {
        return Location::create($data);
    }

    public function update(Location $location, array $data): Location
    {
        $location->update($data);
        return $location->fresh();
    }

    public function delete(Location $location): bool
    {
        return $location->delete();
    }

    public function countForBusiness(int $businessId): int
    {
        return Location::forBusiness($businessId)->count();
    }
}
