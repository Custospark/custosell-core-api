<?php

namespace App\Repositories\Contracts;

use App\Models\FixedAsset;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface FixedAssetRepositoryInterface
{
    public function all(int $businessId, array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?FixedAsset;

    public function create(array $data): FixedAsset;

    public function update(FixedAsset $asset, array $data): FixedAsset;

    public function delete(FixedAsset $asset): bool;

    public function getDueForDepreciation(int $businessId, int $periodId): Collection;
}
