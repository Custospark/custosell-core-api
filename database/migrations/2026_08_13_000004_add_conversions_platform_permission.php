<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const GUARD = 'web';

    /** @var list<string> */
    private const PERMISSIONS = [
        'platform.conversions.view',
    ];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }

        $admin = Role::query()->where('name', 'platform-admin')->where('guard_name', self::GUARD)->first();
        $admin?->givePermissionTo(self::PERMISSIONS);

        $analyst = Role::query()->where('name', 'platform-analyst')->where('guard_name', self::GUARD)->first();
        $analyst?->givePermissionTo(self::PERMISSIONS);
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findByName($name, self::GUARD)?->delete();
        }
    }
};