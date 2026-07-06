<?php

namespace App\Support;

/**
 * Built-in role templates (business_id = null) available to every business.
 */
class StandardRoles
{
    public const PERMISSION_KEYS = [
        'sales.create',
        'sales.view',
        'sales.refund',
        'sales.discount',
        'sales.delete',
        'inventory.view',
        'inventory.create',
        'inventory.edit',
        'inventory.delete',
        'customers.view',
        'customers.create',
        'customers.edit',
        'expenses.view',
        'expenses.create',
        'expenses.edit',
        'expenses.delete',
        'users.view',
        'users.create',
        'users.edit',
        'users.delete',
        'reports.view',
        'shifts.close_report',
        'settings.view',
        'settings.edit',
        'pipeline.view',
        'pipeline.create',
        'pipeline.edit',
        'pipeline.manage',
        'pipeline.convert',
        'estimates.view',
        'estimates.create',
        'estimates.send',
        'estimates.approve',
        'estimates.convert',
        'estimates.delete',
        'projects.view',
        'projects.manage',
        'projects.timesheet',
        'accounting.view',
        'accounting.journal.create',
        'accounting.journal.post',
        'accounting.journal.reverse',
        'accounting.reports',
        'accounting.periods.close',
        'accounting.periods.manage',
        'accounting.assets.manage',
        'accounting.settings',
    ];

    /** @return array<string, bool> */
    public static function allGranted(): array
    {
        return array_fill_keys(self::PERMISSION_KEYS, true);
    }

    /**
     * @param  array<string, bool>  $grants
     * @return array<string, bool>
     */
    public static function permissions(array $grants): array
    {
        $permissions = [];
        foreach (self::PERMISSION_KEYS as $key) {
            $permissions[$key] = (bool) ($grants[$key] ?? false);
        }

        return $permissions;
    }

    /** @return list<array{name: string, slug: string, description: string, permissions: array<string, bool>, is_default: bool}> */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'Admin',
                'slug' => 'admin',
                'description' => 'Full access to all business features',
                'permissions' => self::allGranted(),
                'is_default' => false,
            ],
            [
                'name' => 'Manager',
                'slug' => 'manager',
                'description' => 'Manage sales, inventory, customers, and reports',
                'permissions' => self::permissions([
                    'sales.create' => true,
                    'sales.view' => true,
                    'sales.refund' => true,
                    'sales.discount' => true,
                    'sales.delete' => true,
                    'inventory.view' => true,
                    'inventory.create' => true,
                    'inventory.edit' => true,
                    'inventory.delete' => true,
                    'customers.view' => true,
                    'customers.create' => true,
                    'customers.edit' => true,
                    'expenses.view' => true,
                    'expenses.create' => true,
                    'expenses.edit' => true,
                    'expenses.delete' => true,
                    'reports.view' => true,
                    'shifts.close_report' => true,
                    'settings.view' => true,
                    'pipeline.view' => true,
                    'pipeline.create' => true,
                    'pipeline.edit' => true,
                    'pipeline.manage' => true,
                    'pipeline.convert' => true,
                ]),
                'is_default' => false,
            ],
            [
                'name' => 'Cashier',
                'slug' => 'cashier',
                'description' => 'Point-of-sale and customer checkout',
                'permissions' => self::permissions([
                    'sales.create' => true,
                    'sales.view' => true,
                    'customers.view' => true,
                    'customers.create' => true,
                    'inventory.view' => true,
                    'expenses.view' => true,
                    'expenses.create' => true,
                    'shifts.close_report' => true,
                ]),
                'is_default' => false,
            ],
            [
                'name' => 'Inventory Manager',
                'slug' => 'inventory-manager',
                'description' => 'Manage products, stock, and categories',
                'permissions' => self::permissions([
                    'sales.view' => true,
                    'inventory.view' => true,
                    'inventory.create' => true,
                    'inventory.edit' => true,
                    'inventory.delete' => true,
                    'customers.view' => true,
                    'reports.view' => true,
                ]),
                'is_default' => false,
            ],
            [
                'name' => 'Staff',
                'slug' => 'staff',
                'description' => 'Basic POS access for everyday sales',
                'permissions' => self::permissions([
                    'sales.create' => true,
                    'sales.view' => true,
                    'inventory.view' => true,
                    'customers.view' => true,
                    'customers.create' => true,
                    'inventory.view' => true,
                    'expenses.view' => true,
                    'expenses.create' => true,
                    'shifts.close_report' => true,
                    'accounting.view' => true,
                    'accounting.reports' => true,
                ]),
                'is_default' => true,
            ],
            [
                'name' => 'Supervisor',
                'slug' => 'supervisor',
                'description' => 'Oversee daily operations, shifts, and front-line staff',
                'permissions' => self::permissions([
                    'sales.create' => true,
                    'sales.view' => true,
                    'sales.refund' => true,
                    'sales.discount' => true,
                    'inventory.view' => true,
                    'customers.view' => true,
                    'customers.create' => true,
                    'customers.edit' => true,
                    'expenses.view' => true,
                    'reports.view' => true,
                    'shifts.close_report' => true,
                    'settings.view' => true,
                    'pipeline.view' => true,
                    'pipeline.create' => true,
                    'pipeline.edit' => true,
                    'pipeline.convert' => true,
                ]),
                'is_default' => false,
            ],
            [
                'name' => 'Sales Rep',
                'slug' => 'sales-rep',
                'description' => 'Manage sales pipeline leads and customer conversion',
                'permissions' => self::permissions([
                    'sales.create' => true,
                    'sales.view' => true,
                    'customers.view' => true,
                    'customers.create' => true,
                    'pipeline.view' => true,
                    'pipeline.create' => true,
                    'pipeline.edit' => true,
                    'pipeline.convert' => true,
                ]),
                'is_default' => false,
            ],
            [
                'name' => 'Accountant',
                'slug' => 'accountant',
                'description' => 'Manage expenses, financial records, and business reports',
                'permissions' => self::permissions([
                    'sales.view' => true,
                    'customers.view' => true,
                    'expenses.view' => true,
                    'expenses.create' => true,
                    'expenses.edit' => true,
                    'expenses.delete' => true,
                    'reports.view' => true,
                    'settings.view' => true,
                    'accounting.view' => true,
                    'accounting.journal.create' => true,
                    'accounting.journal.post' => true,
                    'accounting.journal.reverse' => true,
                    'accounting.reports' => true,
                    'accounting.assets.manage' => true,
                ]),
                'is_default' => false,
            ],
        ];
    }
}
