<?php

namespace App\Services;

use App\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use App\Services\Contracts\RoleServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

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

        $this->assertEditableRole($role);

        return $this->roleRepository->update($role, $data);
    }

    public function delete(int $id): bool
    {
        $role = $this->roleRepository->find($id);
        if (!$role) {
            throw new \RuntimeException('Role not found');
        }

        $this->assertEditableRole($role);

        return $this->roleRepository->delete($role);
    }

    public function seedDefaults(int $businessId): void
    {
        // System roles are seeded globally (business_id = null). No per-business copies.
    }

    protected function assertEditableRole(Role $role): void
    {
        if ($this->roleRepository->isSystemTemplate($role)) {
            throw ValidationException::withMessages([
                'role' => 'System roles cannot be modified. Create a custom role instead.',
            ]);
        }
    }
}
