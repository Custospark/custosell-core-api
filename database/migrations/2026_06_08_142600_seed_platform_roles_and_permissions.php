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
        'platform.overview.view',
        'platform.businesses.view',
        'platform.businesses.manage',
        'platform.users.view',
        'platform.users.manage',
        'platform.roles.view',
        'platform.roles.manage',
    ];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }

        $admin = Role::findOrCreate('platform-admin', self::GUARD);
        $admin->syncPermissions(self::PERMISSIONS);

        $analyst = Role::findOrCreate('platform-analyst', self::GUARD);
        $analyst->syncPermissions([
            'platform.overview.view',
            'platform.businesses.view',
        ]);

        $support = Role::findOrCreate('platform-support', self::GUARD);
        $support->syncPermissions([
            'platform.overview.view',
            'platform.businesses.view',
            'platform.businesses.manage',
            'platform.users.view',
            'platform.users.manage',
        ]);
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['platform-admin', 'platform-analyst', 'platform-support'] as $roleName) {
            Role::findByName($roleName, self::GUARD)?->delete();
        }

        foreach (self::PERMISSIONS as $name) {
            Permission::findByName($name, self::GUARD)?->delete();
        }
    }
};
