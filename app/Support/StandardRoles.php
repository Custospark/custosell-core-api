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
                    'expenses.view' => true,
                    'expenses.create' => true,
                    'shifts.close_report' => true,
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
                ]),
                'is_default' => false,
            ],
        ];
    }
}
