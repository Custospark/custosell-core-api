<?php

namespace App\Repositories\Eloquent;

use App\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class RoleRepository implements RoleRepositoryInterface
{
    public function all(int $businessId): Collection
    {
        return Role::where('business_id', $businessId)->get();
    }

    public function find(int $id): ?Role
    {
        return Role::find($id);
    }

    public function findBySlug(int $businessId, string $slug): ?Role
    {
        return Role::where('business_id', $businessId)->where('slug', $slug)->first();
    }

    public function create(array $data): Role
    {
        return Role::create($data);
    }

    public function update(Role $role, array $data): Role
    {
        $role->update($data);
        return $role->fresh();
    }

    public function delete(Role $role): bool
    {
        return $role->delete();
    }

    public function getDefaults(): Collection
    {
        return Role::whereNull('business_id')->get();
    }
}
