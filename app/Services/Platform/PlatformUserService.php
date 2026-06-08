<?php

namespace App\Services\Platform;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class PlatformUserService
{
    public function __construct(
        protected PlatformAdminService $adminService,
        protected PlatformNotificationService $notifications,
        protected PlatformAuditService $audit,
    ) {}

    public function paginateTenantUsers(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = User::query()
            ->with(['business:id,name', 'role:id,name,slug'])
            ->whereNotNull('business_id');

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)->orWhere('email', 'like', $search);
            });
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['business_id'])) {
            $query->where('business_id', (int) $filters['business_id']);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function paginatePlatformTeam(int $perPage = 15): LengthAwarePaginator
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('guard_name', 'web'))
            ->with(['business:id,name', 'roles'])
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function updateStatus(
        User $actor,
        User $target,
        bool $isActive,
        ?string $reason,
        string $channel = 'both',
    ): User
    {
        if ($actor->id === $target->id) {
            throw ValidationException::withMessages(['is_active' => 'You cannot change your own account status.']);
        }

        if (! $isActive && $target->hasRole('platform-admin')) {
            $remaining = User::role('platform-admin')->where('id', '!=', $target->id)->count();
            if ($remaining < 1) {
                throw ValidationException::withMessages(['is_active' => 'Cannot deactivate the last platform admin.']);
            }
        }

        $target->update(['is_active' => $isActive]);

        $this->audit->log(
            $actor,
            $isActive ? 'user.reactivated' : 'user.deactivated',
            'user',
            $target->id,
            $reason,
        );

        $this->notifications->notifyUserStatusChange($target, $isActive, $reason, $channel);

        return $target->fresh(['business', 'role', 'roles']);
    }

    public function assignPlatformRole(User $actor, User $target, string $roleName): User
    {
        if (! $target->hasRole($roleName)) {
            $target->assignRole($roleName);
            $this->audit->log($actor, 'platform_role.assigned', 'user', $target->id, null, ['role' => $roleName]);
        }

        return $target->fresh(['roles']);
    }

    public function revokePlatformRole(User $actor, User $target, string $roleName): User
    {
        if ($roleName === 'platform-admin' && $target->hasRole('platform-admin')) {
            $remaining = User::role('platform-admin')->where('id', '!=', $target->id)->count();
            if ($remaining < 1) {
                throw ValidationException::withMessages(['role' => 'Cannot remove the last platform admin role.']);
            }
        }

        $target->removeRole($roleName);
        $this->audit->log($actor, 'platform_role.revoked', 'user', $target->id, null, ['role' => $roleName]);

        return $target->fresh(['roles']);
    }
}
