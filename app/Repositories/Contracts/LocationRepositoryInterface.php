<?php

namespace App\Repositories\Contracts;

use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;

interface LocationRepositoryInterface
{
    public function all(int $businessId): Collection;

    public function active(int $businessId): Collection;

    public function find(int $id): ?Location;

    public function default(int $businessId): ?Location;

    public function create(array $data): Location;

    public function update(Location $location, array $data): Location;

    public function delete(Location $location): bool;

    public function countForBusiness(int $businessId): int;
}
