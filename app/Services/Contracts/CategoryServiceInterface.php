<?php

namespace App\Services\Contracts;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;

interface CategoryServiceInterface
{
    public function getAll(int $businessId): Collection;

    public function getById(int $id): ?Category;

    public function create(int $businessId, array $data): Category;

    public function update(int $id, array $data): Category;

    public function delete(int $id): bool;
}
