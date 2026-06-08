<?php

namespace App\Repositories\Eloquent;

use App\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class RoleRepository implements RoleRepositoryInterface
{
    public function all(int $businessId): Collection
    {
        $system = Role::query()
            ->whereNull('business_id')
            ->orderBy('name')
            ->get();

        $custom = Role::query()
            ->where('business_id', $businessId)
            ->orderBy('name')
            ->get();

        return $system->concat($custom);
    }

    public function find(int $id): ?Role
    {
        return Role::find($id);
    }

    public function findBySlug(int $businessId, string $slug): ?Role
    {
        return Role::query()
            ->where('slug', $slug)
            ->where(function ($query) use ($businessId) {
                $query->whereNull('business_id')
                    ->orWhere('business_id', $businessId);
            })
            ->first();
    }

    public function findAvailableForBusiness(int $businessId, int $roleId): ?Role
    {
        return Role::query()
            ->whereKey($roleId)
            ->where(function ($query) use ($businessId) {
                $query->whereNull('business_id')
                    ->orWhere('business_id', $businessId);
            })
            ->first();
    }

    public function isSystemTemplate(Role $role): bool
    {
        return $role->business_id === null;
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
