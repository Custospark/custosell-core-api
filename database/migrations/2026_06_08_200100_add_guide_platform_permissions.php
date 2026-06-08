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
        'platform.guide.view',
        'platform.guide.manage',
        'platform.guide.feedback.manage',
    ];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }

        $admin = Role::findByName('platform-admin', self::GUARD);
        $admin?->givePermissionTo(self::PERMISSIONS);

        $support = Role::findByName('platform-support', self::GUARD);
        if ($support) {
            $support->givePermissionTo([
                'platform.guide.view',
                'platform.guide.feedback.manage',
            ]);
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findByName($name, self::GUARD)?->delete();
        }
    }
};
