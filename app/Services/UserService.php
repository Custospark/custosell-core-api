<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Contracts\UserServiceInterface;
use App\Services\ModuleAccessService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UserService implements UserServiceInterface
{
    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected ModuleAccessService $moduleAccess,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->userRepository->all($businessId);
    }

    public function getById(int $id): ?User
    {
        return $this->userRepository->find($id);
    }

    public function getByIdForBusiness(int $id, int $businessId): ?User
    {
        return $this->userRepository->findForBusiness($id, $businessId);
    }

    public function register(array $data): User
    {
        $data['password'] = Hash::make($data['password']);
        return $this->userRepository->create($data);
    }

    public function login(string $email, string $password): ?User
    {
        $user = $this->userRepository->findByEmail($email);
        if (!$user || !Hash::check($password, $user->password)) {
            return null;
        }
        return $user;
    }

    public function createStaff(int $businessId, array $data): User
    {
        $data['business_id'] = $businessId;
        $data['password'] = Hash::make($data['password']);
        $data['created_by'] = Auth::id();

        if (array_key_exists('modules', $data)) {
            $data['modules'] = $this->moduleAccess->normalizeStaffModules($data['modules'], allowEmpty: true);
        } else {
            $data['modules'] = [];
        }

        if (array_key_exists('role_id', $data) && $data['role_id'] !== null) {
            $this->assertRoleAvailableForBusiness($businessId, (int) $data['role_id']);
        }

        return $this->userRepository->create($data)->load('role');
    }

    public function update(int $id, int $businessId, int $actorId, array $data): User
    {
        $user = $this->userRepository->findForBusiness($id, $businessId);
        if (!$user) {
            throw new NotFoundHttpException('User not found');
        }

        $this->validateRoleUpdate($user, $businessId, $actorId, $data);
        $this->validateActivationUpdate($user, $businessId, $actorId, $data);
        $this->validateModulesUpdate($user, $businessId, $data);

        if (isset($data['password']) && trim((string) $data['password']) === '') {
            unset($data['password']);
        }
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        return $this->userRepository->update($user, $data);
    }

    public function delete(int $id, int $businessId, int $actorId): bool
    {
        $user = $this->userRepository->findForBusiness($id, $businessId);
        if (!$user) {
            throw new NotFoundHttpException('User not found');
        }
        $this->validateDelete($user, $businessId, $actorId);
        return $this->userRepository->delete($user);
    }

    public function clampStaffModulesAfterOwnerUpdate(User $owner): void
    {
        if (! $this->moduleAccess->isBusinessOwner($owner) || ! $owner->business_id) {
            return;
        }

        $staff = User::query()
            ->where('business_id', $owner->business_id)
            ->whereKeyNot($owner->id)
            ->get();

        foreach ($staff as $member) {
            $clamped = $this->moduleAccess->clampStaffModulesToOwnerCatalog(
                $this->moduleAccess->storedStaffModules($member),
                $owner,
            );
            if ($clamped !== $this->moduleAccess->storedStaffModules($member)) {
                $member->update(['modules' => $clamped]);
            }
        }
    }

    public function countByBusiness(int $businessId): int
    {
        return $this->userRepository->countByBusiness($businessId);
    }

    protected function validateRoleUpdate(User $user, int $businessId, int $actorId, array $data): void
    {
        if (!array_key_exists('role_id', $data)) {
            return;
        }

        if ($data['role_id'] === null) {
            if ($user->role_id === null) {
                return;
            }

            throw ValidationException::withMessages([
                'role_id' => 'Select a valid staff role before changing this account role.',
            ]);
        }

        $nextRoleId = (int) $data['role_id'];
        if ($nextRoleId === (int) $user->role_id) {
            return;
        }

        if ($user->id === $actorId) {
            throw ValidationException::withMessages([
                'role_id' => 'You cannot change your own role.',
            ]);
        }

        if ($this->isBusinessOwner($user, $businessId)) {
            throw ValidationException::withMessages([
                'role_id' => 'The business owner account role cannot be changed.',
            ]);
        }

        $nextRole = Role::query()
            ->whereKey($nextRoleId)
            ->where(function ($query) use ($businessId) {
                $query->whereNull('business_id')
                    ->orWhere('business_id', $businessId);
            })
            ->first();

        if (!$nextRole) {
            throw ValidationException::withMessages([
                'role_id' => 'The selected role is not available for this business.',
            ]);
        }
    }

    protected function validateActivationUpdate(User $user, int $businessId, int $actorId, array $data): void
    {
        if (!array_key_exists('is_active', $data) || (bool) $data['is_active']) {
            return;
        }

        if ($user->id === $actorId) {
            throw ValidationException::withMessages([
                'is_active' => 'You cannot deactivate your own account.',
            ]);
        }

        if ($this->isBusinessOwner($user, $businessId)) {
            throw ValidationException::withMessages([
                'is_active' => 'The business owner account cannot be deactivated.',
            ]);
        }
    }

    protected function validateModulesUpdate(User $user, int $businessId, array &$data): void
    {
        if (! array_key_exists('modules', $data)) {
            return;
        }

        if ($this->isBusinessOwner($user, $businessId)) {
            unset($data['modules']);

            return;
        }

        $data['modules'] = $this->moduleAccess->normalizeStaffModules($data['modules'], allowEmpty: true);
    }

    protected function validateDelete(User $user, int $businessId, int $actorId): void
    {
        if ($user->id === $actorId) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own account.',
            ]);
        }

        if ($this->isBusinessOwner($user, $businessId)) {
            throw ValidationException::withMessages([
                'user' => 'The business owner account cannot be deleted.',
            ]);
        }
    }

    protected function isBusinessOwner(User $user, int $businessId): bool
    {
        return Business::query()
            ->whereKey($businessId)
            ->where('owner_id', $user->id)
            ->exists();
    }

    protected function assertRoleAvailableForBusiness(int $businessId, int $roleId): void
    {
        $available = Role::query()
            ->whereKey($roleId)
            ->where(function ($query) use ($businessId) {
                $query->whereNull('business_id')
                    ->orWhere('business_id', $businessId);
            })
            ->exists();

        if (!$available) {
            throw ValidationException::withMessages([
                'role_id' => 'The selected role is not available for this business.',
            ]);
        }
    }
}
