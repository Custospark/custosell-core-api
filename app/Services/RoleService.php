<?php

namespace App\Services;

use App\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use App\Services\Contracts\RoleServiceInterface;
use Illuminate\Database\Eloquent\Collection;

class RoleService implements RoleServiceInterface
{
    public function __construct(
        protected RoleRepositoryInterface $roleRepository,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->roleRepository->all($businessId);
    }

    public function getById(int $id): ?Role
    {
        return $this->roleRepository->find($id);
    }

    public function create(int $businessId, array $data): Role
    {
        $data['business_id'] = $businessId;
        return $this->roleRepository->create($data);
    }

    public function update(int $id, array $data): Role
    {
        $role = $this->roleRepository->find($id);
        if (!$role) {
            throw new \RuntimeException('Role not found');
        }
        return $this->roleRepository->update($role, $data);
    }

    public function delete(int $id): bool
    {
        $role = $this->roleRepository->find($id);
        if (!$role) {
            throw new \RuntimeException('Role not found');
        }
        return $this->roleRepository->delete($role);
    }

    public function seedDefaults(int $businessId): void
    {
        $this->roleRepository->create([
            'business_id' => $businessId,
            'name' => 'Admin',
            'slug' => 'admin',
            'description' => 'Full access to all features',
            'permissions' => [
                'sales.create' => true,
                'sales.view' => true,
                'sales.refund' => true,
                'sales.discount' => true,
                'sales.delete' => true,
                'inventory.view' => true,
                'inventory.create' => true,
                'inventory.edit' => true,
                'inventory.delete' => true,
                'customers.view' => true,
                'customers.create' => true,
                'customers.edit' => true,
                'expenses.view' => true,
                'expenses.create' => true,
                'expenses.edit' => true,
                'expenses.delete' => true,
                'users.view' => true,
                'users.create' => true,
                'users.edit' => true,
                'users.delete' => true,
                'reports.view' => true,
                'shifts.close_report' => true,
                'settings.view' => true,
                'settings.edit' => true,
            ],
            'is_default' => true,
        ]);

        $this->roleRepository->create([
            'business_id' => $businessId,
            'name' => 'Staff',
            'slug' => 'staff',
            'description' => 'Limited POS access',
            'permissions' => [
                'sales.create' => true,
                'sales.view' => true,
                'shifts.close_report' => true,
                'sales.refund' => false,
                'sales.discount' => false,
                'sales.delete' => false,
                'inventory.view' => true,
                'inventory.create' => false,
                'inventory.edit' => false,
                'inventory.delete' => false,
                'customers.view' => true,
                'customers.create' => true,
                'customers.edit' => false,
                'expenses.view' => false,
                'expenses.create' => false,
                'expenses.edit' => false,
                'expenses.delete' => false,
                'users.view' => false,
                'users.create' => false,
                'users.edit' => false,
                'users.delete' => false,
                'reports.view' => false,
                'settings.view' => false,
                'settings.edit' => false,
            ],
            'is_default' => true,
        ]);
    }
}
