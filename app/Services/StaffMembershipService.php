<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Business;
use App\Models\Hr\HrEmployee;
use App\Models\Role;
use App\Models\User;
use App\Services\Hr\HrStaffMirrorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StaffMembershipService
{
    public function __construct(
        protected ModuleAccessService $moduleAccess,
        protected HrStaffMirrorService $hrStaffMirror,
    ) {}

    /**
     * @return array{
     *   status: 'available'|'unattached'|'already_member'|'other_business'|'soft_deleted'|'platform_inactive',
     *   user?: array{id: int, name: string, email: string}
     * }
     */
    public function lookupByEmail(string $email, User $actor): array
    {
        $businessId = (int) $actor->business_id;
        if ($businessId <= 0) {
            throw ValidationException::withMessages([
                'email' => 'You must belong to a business to look up staff.',
            ]);
        }

        $normalized = strtolower(trim($email));
        $user = User::withTrashed()
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->first();

        if (! $user) {
            return ['status' => 'available'];
        }

        $public = [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
        ];

        if ($user->trashed()) {
            return ['status' => 'soft_deleted', 'user' => $public];
        }

        if (! $user->is_active) {
            return ['status' => 'platform_inactive', 'user' => $public];
        }

        if ($user->business_id === null) {
            return ['status' => 'unattached', 'user' => $public];
        }

        if ((int) $user->business_id === $businessId) {
            return ['status' => 'already_member', 'user' => $public];
        }

        return ['status' => 'other_business', 'user' => $public];
    }

    /**
     * @param  array{
     *   email: string,
     *   role_id: int,
     *   modules?: list<string>|null,
     *   name?: string|null,
     *   phone?: string|null,
     *   link_employee_id?: int|null
     * }  $data
     */
    public function attachStaff(User $actor, array $data): User
    {
        $businessId = (int) $actor->business_id;
        if ($businessId <= 0) {
            throw ValidationException::withMessages([
                'email' => 'You must belong to a business to attach staff.',
            ]);
        }

        $email = strtolower(trim((string) $data['email']));
        $roleId = (int) ($data['role_id'] ?? 0);
        if ($roleId <= 0) {
            throw ValidationException::withMessages([
                'role_id' => 'Select a role before attaching this person.',
            ]);
        }

        $this->assertRoleAvailableForBusiness($businessId, $roleId);

        return DB::transaction(function () use ($actor, $businessId, $email, $roleId, $data) {
            $user = User::withTrashed()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            if (! $user) {
                throw new NotFoundHttpException('No account found for this email. Create a new staff login instead.');
            }

            if ($user->trashed()) {
                $user->restore();
            }

            if (! $user->is_active) {
                throw ValidationException::withMessages([
                    'email' => 'This account is deactivated by Custosell and cannot be attached.',
                ]);
            }

            if ($user->business_id !== null && (int) $user->business_id === $businessId) {
                throw ValidationException::withMessages([
                    'email' => 'This person is already on your staff list.',
                ]);
            }

            if ($user->business_id !== null) {
                throw new ConflictHttpException(
                    'This email is already used by someone on another organization. Ask them to detach there first, or use a different email.'
                );
            }

            $modules = array_key_exists('modules', $data)
                ? $this->moduleAccess->normalizeStaffModules($data['modules'] ?? [], allowEmpty: true)
                : [];

            $update = [
                'business_id' => $businessId,
                'role_id' => $roleId,
                'modules' => $modules,
            ];

            if (! empty($data['name'])) {
                $update['name'] = trim((string) $data['name']);
            }
            if (array_key_exists('phone', $data)) {
                $update['phone'] = $data['phone'];
            }

            $user->fill($update);
            $user->save();

            $linkEmployeeId = isset($data['link_employee_id']) ? (int) $data['link_employee_id'] : 0;
            if ($linkEmployeeId > 0) {
                $this->linkEmployeeToUser($businessId, $linkEmployeeId, (int) $user->id, (int) $actor->id);
            } elseif (empty($data['skip_hr_mirror'])) {
                $this->hrStaffMirror->ensureEmployeeForUser($user->fresh() ?? $user, (int) $actor->id);
            }

            return $user->fresh(['role']) ?? $user->load('role');
        });
    }

    public function detachStaff(User $actor, int $userId): User
    {
        $businessId = (int) $actor->business_id;
        if ($businessId <= 0) {
            throw ValidationException::withMessages([
                'user' => 'You must belong to a business to detach staff.',
            ]);
        }

        $user = User::query()
            ->where('business_id', $businessId)
            ->whereKey($userId)
            ->first();

        if (! $user) {
            throw new NotFoundHttpException('User not found');
        }

        if ((int) $user->id === (int) $actor->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot detach your own account.',
            ]);
        }

        if ($this->isBusinessOwner($user, $businessId)) {
            throw ValidationException::withMessages([
                'user' => 'The business owner cannot be detached.',
            ]);
        }

        return DB::transaction(function () use ($businessId, $user) {
            HrEmployee::query()
                ->where('business_id', $businessId)
                ->where('user_id', $user->id)
                ->update(['user_id' => null]);

            $user->business_id = null;
            $user->role_id = null;
            $user->modules = [];
            $user->save();

            $user->tokens()->delete();

            return $user->fresh() ?? $user;
        });
    }

    protected function linkEmployeeToUser(int $businessId, int $employeeId, int $userId, int $actorUserId): void
    {
        $employee = HrEmployee::query()
            ->where('business_id', $businessId)
            ->whereKey($employeeId)
            ->first();

        if (! $employee) {
            throw ValidationException::withMessages([
                'link_employee_id' => 'HR employee not found.',
            ]);
        }

        if ($employee->user_id && (int) $employee->user_id !== $userId) {
            throw ValidationException::withMessages([
                'link_employee_id' => 'This employee already has a different app login.',
            ]);
        }

        $employee->user_id = $userId;
        $employee->save();
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

        if (! $available) {
            throw ValidationException::withMessages([
                'role_id' => 'The selected role is not available for this business.',
            ]);
        }
    }
}
