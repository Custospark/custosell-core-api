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

        if (! $user->hasRole('platform-admin')) {
            $user->assignRole('platform-admin');
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
        return $user->roles()->where('guard_name', 'web')->exists();
    }

    /** @return list<string> */
    public function platformRolesFor(User $user): array
    {
        return $user->getRoleNames()->values()->all();
    }

    /** @return list<string> */
    public function platformPermissionsFor(User $user): array
    {
        return $user->getAllPermissions()->pluck('name')->values()->all();
    }

    public function userHasPlatformPermission(User $user, string $permission): bool
    {
        if (! str_starts_with($permission, 'platform.')) {
            return false;
        }

        return $user->hasPermissionTo($permission);
    }
}
