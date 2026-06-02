<?php

namespace App\Services\Contracts;

use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

interface RoleServiceInterface
{
    public function getAll(int $businessId): Collection;

    public function getById(int $id): ?Role;

    public function create(int $businessId, array $data): Role;

    public function update(int $id, array $data): Role;

    public function delete(int $id): bool;

    public function seedDefaults(int $businessId): void;
}
