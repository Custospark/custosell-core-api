<?php

namespace App\Services\Platform;

use App\Models\User;
use Spatie\Permission\Models\Role;

class PlatformAdminService
{
    public function isAdminEmail(string $email): bool
    {
        return in_array(strtolower(trim($email)), config('platform.admin_emails', []), true);
    }

    public function assignIfEligible(User $user): void
    {
        if (! $this->isAdminEmail($user->email)) {
            return;
        }

        if ($this->isPlatformAdmin($user)) {
            return;
        }

        $user->assignRole('platform-admin');
        if ($user->relationLoaded('roles')) {
            $user->load('roles');
        }
    }

    public function syncConfiguredAdminEmails(): int
    {
        $count = 0;
        foreach (config('platform.admin_emails', []) as $email) {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($user) {
                $this->assignIfEligible($user);
                $count++;
            }
        }

        return $count;
    }

    public function isPlatformAdmin(User $user): bool
    {
        if ($user->relationLoaded('roles')) {
            return $user->roles->isNotEmpty();
        }

        return $user->roles()->where('guard_name', 'web')->exists();
    }

    /** @return array{is_platform_admin: bool, platform_roles: list<string>} */
    public function platformMetaFor(User $user): array
    {
        if ($user->relationLoaded('roles')) {
            $roles = $user->roles->pluck('name')->values()->all();

            return [
                'is_platform_admin' => $roles !== [],
                'platform_roles' => $roles,
            ];
        }

        $roles = $user->getRoleNames()->values()->all();

        return [
            'is_platform_admin' => $roles !== [],
            'platform_roles' => $roles,
        ];
    }

    /** @return list<string> */
    public function platformRolesFor(User $user): array
    {
        return $this->platformMetaFor($user)['platform_roles'];
    }

    public function userHasPlatformPermission(User $user, string $permission): bool
    {
        if (! str_starts_with($permission, 'platform.')) {
            return false;
        }

        return $user->hasPermissionTo($permission);
    }
}
