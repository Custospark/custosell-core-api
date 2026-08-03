<?php

namespace App\Services\Contracts;

use App\Models\Location;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Collection;

interface LocationServiceInterface
{
    public function getAll(int $businessId): Collection;

    public function getActive(int $businessId): Collection;

    public function getById(int $id): ?Location;

    public function getDefault(int $businessId): ?Location;

    public function ensureDefaultLocation(int $businessId): ?Location;

    public function create(int $businessId, array $data): Location;

    public function update(int $id, array $data): Location;

    public function delete(int $id): bool;

    public function setDefault(int $id): Location;

    public function assignUserToLocations(int $userId, array $locationIds): void;

    public function userLocationIds(int $userId): array;

    public function maxLocationsFor(Subscription $subscription): ?int;
}
