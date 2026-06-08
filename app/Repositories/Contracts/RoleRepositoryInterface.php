<?php

namespace App\Repositories\Contracts;

use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

interface RoleRepositoryInterface
{
    public function all(int $businessId): Collection;

    public function find(int $id): ?Role;

    public function findBySlug(int $businessId, string $slug): ?Role;

    public function findAvailableForBusiness(int $businessId, int $roleId): ?Role;

    public function isSystemTemplate(Role $role): bool;

    public function create(array $data): Role;

    public function update(Role $role, array $data): Role;

    public function delete(Role $role): bool;

    public function getDefaults(): Collection;
}
