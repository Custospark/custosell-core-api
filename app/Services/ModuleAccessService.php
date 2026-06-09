<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use App\Services\Platform\PlatformAdminService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ModuleAccessService
{
    public const BUSINESS_MODULES = [
        'dashboard',
        'sales',
        'inventory',
        'customers',
        'expenses',
        'settings',
    ];

    public const PUBLIC_MODULES = [
        'account',
        'guide',
    ];

    public const PLATFORM_MODULES = [
        'platform',
        'guide_settings',
    ];

    /** @return list<string> */
    public static function businessModuleSlugs(): array
    {
        return self::BUSINESS_MODULES;
    }

    public function isBusinessOwner(User $user): bool
    {
        if (! $user->business_id) {
            return false;
        }

        if ($user->relationLoaded('business')) {
            return (int) $user->business?->owner_id === (int) $user->id;
        }

        return Business::query()
            ->whereKey($user->business_id)
            ->where('owner_id', $user->id)
            ->exists();
    }

    /** @return list<string> */
    public function storedBusinessModules(User $user): array
    {
        $modules = is_array($user->modules) ? $user->modules : [];

        return array_values(array_intersect($modules, self::BUSINESS_MODULES));
    }

    /** @return list<string> */
    public function accessibleModules(User $user): array
    {
        $modules = [...self::PUBLIC_MODULES];

        if (app(PlatformAdminService::class)->isPlatformAdmin($user)) {
            $modules = array_merge($modules, self::PLATFORM_MODULES);
        }

        if ($this->isBusinessOwner($user)) {
            $modules = array_merge($modules, self::BUSINESS_MODULES);
        } else {
            $modules = array_merge($modules, $this->storedBusinessModules($user));
        }

        return array_values(array_unique($modules));
    }

    public function canAccess(User $user, string $module): bool
    {
        if (in_array($module, self::PUBLIC_MODULES, true)) {
            return true;
        }

        if (in_array($module, self::PLATFORM_MODULES, true)) {
            return app(PlatformAdminService::class)->isPlatformAdmin($user);
        }

        if (! in_array($module, self::BUSINESS_MODULES, true)) {
            return false;
        }

        if ($this->isBusinessOwner($user)) {
            return true;
        }

        return in_array($module, $this->storedBusinessModules($user), true);
    }

    /** @param  list<string>|null  $modules */
    public function validateBusinessModules(?array $modules, bool $allowEmpty = true): array
    {
        if ($modules === null) {
            return [];
        }

        $validator = Validator::make(
            ['modules' => $modules],
            [
                'modules' => [$allowEmpty ? 'nullable' : 'required', 'array'],
                'modules.*' => ['string', 'in:'.implode(',', self::BUSINESS_MODULES)],
            ],
        );

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        return array_values(array_unique($modules));
    }

    /** @return list<string> */
    public function fullBusinessModulesForOwner(): array
    {
        return self::BUSINESS_MODULES;
    }

    /**
     * Maps legacy permission keys to the business module that grants them.
     * Staff module access is the source of truth — role permission flags are not enforced.
     */
    public function moduleForPermission(string $permission): ?string
    {
        if (str_starts_with($permission, 'platform.')) {
            return null;
        }

        $area = explode('.', $permission, 2)[0];

        return match ($area) {
            'sales', 'shifts' => 'sales',
            'inventory', 'products' => 'inventory',
            'customers' => 'customers',
            'expenses' => 'expenses',
            'users', 'staff' => 'settings',
            'settings' => 'settings',
            'reports' => 'dashboard',
            default => null,
        };
    }

    /** Sales or Expenses module — record/read shift and general expenses. */
    public function canAccessExpenseWorkflow(User $user): bool
    {
        return $this->canAccess($user, 'sales') || $this->canAccess($user, 'expenses');
    }

    /** Whether a staff member may perform an action based on assigned modules (owners: always). */
    public function canPerform(User $user, string $permission): bool
    {
        if ($this->isBusinessOwner($user)) {
            return true;
        }

        if (str_starts_with($permission, 'expenses.')) {
            return $this->canAccessExpenseWorkflow($user);
        }

        $module = $this->moduleForPermission($permission);
        if ($module === null) {
            return false;
        }

        return $this->canAccess($user, $module);
    }
}
