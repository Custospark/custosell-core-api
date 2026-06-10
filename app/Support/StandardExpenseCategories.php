<?php

namespace App\Support;

/**
 * Built-in expense category templates (business_id = null) available to every business.
 */
class StandardExpenseCategories
{
    /** @return list<array{slug: string, name: string, description: string, sort_order: int}> */
    public static function definitions(): array
    {
        return [
            [
                'slug' => 'rent',
                'name' => 'Rent',
                'description' => 'Store, office, or warehouse rent',
                'sort_order' => 10,
            ],
            [
                'slug' => 'utilities',
                'name' => 'Utilities',
                'description' => 'Electricity, water, internet, and phone bills',
                'sort_order' => 20,
            ],
            [
                'slug' => 'salaries-wages',
                'name' => 'Salaries & Wages',
                'description' => 'Staff salaries, wages, and casual labour',
                'sort_order' => 30,
            ],
            [
                'slug' => 'stock-supplies',
                'name' => 'Stock & Supplies',
                'description' => 'Inventory purchases and operating supplies',
                'sort_order' => 40,
            ],
            [
                'slug' => 'transport-delivery',
                'name' => 'Transport & Delivery',
                'description' => 'Fuel, delivery, and transport costs',
                'sort_order' => 50,
            ],
            [
                'slug' => 'marketing',
                'name' => 'Marketing',
                'description' => 'Advertising, promotions, and signage',
                'sort_order' => 60,
            ],
            [
                'slug' => 'repairs-maintenance',
                'name' => 'Repairs & Maintenance',
                'description' => 'Equipment, premises, and vehicle maintenance',
                'sort_order' => 70,
            ],
            [
                'slug' => 'bank-fees',
                'name' => 'Bank & Transaction Fees',
                'description' => 'Bank charges, mobile money, and payment fees',
                'sort_order' => 80,
            ],
            [
                'slug' => 'licenses-taxes',
                'name' => 'Licenses & Taxes',
                'description' => 'Business licenses, URA, and statutory taxes',
                'sort_order' => 90,
            ],
            [
                'slug' => 'insurance',
                'name' => 'Insurance',
                'description' => 'Business, stock, and liability insurance',
                'sort_order' => 100,
            ],
            [
                'slug' => 'meals-welfare',
                'name' => 'Meals & Staff Welfare',
                'description' => 'Staff meals, tea, and welfare expenses',
                'sort_order' => 110,
            ],
            [
                'slug' => 'miscellaneous',
                'name' => 'Miscellaneous',
                'description' => 'Other business expenses',
                'sort_order' => 120,
            ],
        ];
    }
}
