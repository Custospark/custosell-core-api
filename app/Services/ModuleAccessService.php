<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use App\Services\Platform\PlatformAdminService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ModuleAccessService
{
    public const ESTIMATES_FULL_SLUG = 'estimates_full';

    public const BUSINESS_MODULES = [
        'dashboard',
        'sales',
        'inventory',
        'customers',
        'expenses',
        'accounting',
        'pipeline',
        'estimates',
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

    /** @return list<string> */
    public static function assignableModuleSlugs(): array
    {
        return [...self::BUSINESS_MODULES, self::ESTIMATES_FULL_SLUG];
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
    public function storedStaffModules(User $user): array
    {
        return is_array($user->modules) ? array_values($user->modules) : [];
    }

    public function hasFullEstimatesWorkspace(User $user): bool
    {
        return in_array(self::ESTIMATES_FULL_SLUG, $this->storedStaffModules($user), true);
    }

    public function ownerHasLegacyFullEstimatesAccess(User $user): bool
    {
        return $this->hasFullEstimatesWorkspace($user);
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
            $modules = array_merge($modules, $this->resolvedOwnerBusinessModules($user));
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

        // Sales and Customers are base modules — every business user needs them
        // regardless of stored module permissions. The Staff modules UI controls
        // navigation visibility, but the backend never blocks these.
        if ($user->business_id && in_array($module, ['sales', 'customers'], true)) {
            return true;
        }

        if ($this->isBusinessOwner($user)) {
            if ($module === 'settings') {
                return true;
            }

            return in_array($module, $this->resolvedOwnerBusinessModules($user), true);
        }

        $modules = $this->storedBusinessModules($user);

        // Customers depends on Sales for shared flows (e.g. customer purchases, invoices, sales catalog snapshots).
        // Treat `customers` as granting access to `sales` to avoid 403s for staff who were intentionally given
        // only customers, while still keeping other modules locked down.
        if ($module === 'sales' && in_array('customers', $modules, true)) {
            return true;
        }

        return in_array($module, $modules, true);
    }

    /** @param  list<string>|null  $modules */
    public function normalizeStaffModules(?array $modules, bool $allowEmpty = true): array
    {
        if ($modules === null) {
            return [];
        }

        $wantsFullEstimates = in_array(self::ESTIMATES_FULL_SLUG, $modules, true);
        $businessOnly = array_values(array_filter(
            $modules,
            fn (string $module) => $module !== self::ESTIMATES_FULL_SLUG,
        ));
        $validated = $this->validateBusinessModules($businessOnly, $allowEmpty);

        // Customers implies Sales (dependency).
        if (in_array('customers', $validated, true) && ! in_array('sales', $validated, true)) {
            $validated[] = 'sales';
        }

        if ($wantsFullEstimates) {
            if (! in_array('estimates', $validated, true)) {
                $validated[] = 'estimates';
            }
            $validated[] = self::ESTIMATES_FULL_SLUG;
        }

        return array_values(array_unique($validated));
    }

    /** @param  list<string>|null  $modules */
    public function normalizeOwnerModules(?array $modules): array
    {
        if ($modules === null) {
            return self::BUSINESS_MODULES;
        }

        $wantsFullEstimates = in_array(self::ESTIMATES_FULL_SLUG, $modules, true);
        $businessOnly = array_values(array_filter(
            $modules,
            fn (string $module) => $module !== self::ESTIMATES_FULL_SLUG,
        ));
        $validated = $this->validateBusinessModules($businessOnly, allowEmpty: false);

        if (! in_array('settings', $validated, true)) {
            $validated[] = 'settings';
        }

        if ($wantsFullEstimates) {
            if (! in_array('estimates', $validated, true)) {
                $validated[] = 'estimates';
            }
            $validated[] = self::ESTIMATES_FULL_SLUG;
        }

        return array_values(array_unique($validated));
    }

    /** @param  list<string>  $staffModules */
    public function clampStaffModulesToOwnerCatalog(array $staffModules, User $owner): array
    {
        $ownerCatalog = array_flip($this->resolvedOwnerBusinessModules($owner));
        $wantsFull = in_array(self::ESTIMATES_FULL_SLUG, $staffModules, true);
        $ownerHasFull = $this->hasFullEstimatesWorkspace($owner);

        $business = array_values(array_filter(
            $staffModules,
            fn (string $module) => $module !== self::ESTIMATES_FULL_SLUG && isset($ownerCatalog[$module]),
        ));

        $normalized = $this->normalizeStaffModules($business, allowEmpty: true);

        if ($wantsFull && $ownerHasFull && in_array('estimates', $normalized, true)) {
            $normalized[] = self::ESTIMATES_FULL_SLUG;
        }

        return array_values(array_unique($normalized));
    }

    /** @return list<string> */
    public function resolvedOwnerBusinessModules(User $user): array
    {
        $modules = $this->storedBusinessModules($user);

        if ($modules === []) {
            return self::BUSINESS_MODULES;
        }

        if (! in_array('settings', $modules, true)) {
            $modules[] = 'settings';
        }

        return array_values(array_unique($modules));
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
            'accounting' => 'accounting',
            'pipeline' => 'pipeline',
            'estimates', 'projects' => 'estimates',
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
