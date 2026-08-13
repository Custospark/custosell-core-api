<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        // Remove old per-module personal plans — replaced by the unified Personal plan below.
        Plan::whereIn('slug', [
            'pipeline-personal', 'accounting-personal',
            'estimates-personal', 'expenses-personal', 'documents-personal',
        ])->delete();

        // Create-only: plans are managed in the UI after seeding. This seeder must
        // never overwrite existing plans, so it only inserts missing ones.
        Plan::firstOrCreate(['slug' => 'essential'], [
            'name' => 'Essential',
            'slug' => 'essential',
            'type' => 'business',
            'description' => 'Point of sale, inventory, and online storefront for small businesses.',
            'price_monthly_usd' => 19.99,
            'price_yearly_usd' => 199.9,
            'onboarding_fee_usd' => 0,
            'trial_days' => 30,
            'billing_cycle' => 'both',
            'is_popular' => false,
            'features' => [
                'sales' => true,
                'customers' => true,
                'inventory' => true,
                'expenses' => true,
                'dashboard' => true,
                'storefront' => true,
            ],
            'limits' => [
                'max_staff' => 3,
                'max_products' => 500,
                'max_businesses' => 1,
                'max_board_members' => 10,
                'max_locations' => 1,
            ],
            'sort_order' => 1,
            'is_active' => true,
        ]);

        Plan::firstOrCreate(['slug' => 'professional'], [
            'name' => 'Professional',
            'slug' => 'professional',
            'type' => 'business',
            'description' => 'Full business management suite for growing businesses — including pipeline, estimates, and document management.',
            'price_monthly_usd' => 29.99,
            'price_yearly_usd' => 299.9,
            'onboarding_fee_usd' => 0,
            'trial_days' => 30,
            'billing_cycle' => 'both',
            'is_popular' => true,
            'features' => [
                'sales' => true,
                'customers' => true,
                'inventory' => true,
                'expenses' => true,
                'dashboard' => true,
                'storefront' => true,
                'pipeline' => true,
                'estimates' => true,
                'documents' => true,
                'marketplace' => true,
            ],
            'limits' => [
                'max_staff' => 20,
                'max_products' => 5000,
                'max_businesses' => 3,
                'max_board_members' => 50,
                'max_locations' => 3,
            ],
            'sort_order' => 2,
            'is_active' => true,
        ]);

        Plan::firstOrCreate(['slug' => 'personal'], [
            'name' => 'Personal',
            'slug' => 'personal',
            'type' => 'personal',
            'description' => 'All the tools you need for personal productivity — pipeline, estimates, expenses, accounting, and documents.',
            'price_monthly_usd' => 9.99,
            'price_yearly_usd' => 99.9,
            'onboarding_fee_usd' => 0,
            'trial_days' => 30,
            'billing_cycle' => 'both',
            'is_popular' => false,
            'features' => [
                'pipeline' => true,
                'estimates' => true,
                'estimates_full' => true,
                'expenses' => true,
                'accounting' => true,
                'documents' => true,
            ],
            'limits' => [
                'max_board_members' => 5,
            ],
            'sort_order' => 10,
            'is_active' => true,
        ]);

        Plan::firstOrCreate(['slug' => 'enterprise'], [
            'name' => 'Enterprise',
            'slug' => 'enterprise',
            'type' => 'business',
            'description' => 'Unlimited everything for large organizations and multi-branch operations.',
            'price_monthly_usd' => 49.99,
            'price_yearly_usd' => 499.9,
            'onboarding_fee_usd' => 0,
            'trial_days' => 30,
            'billing_cycle' => 'both',
            'is_popular' => false,
            'features' => [
                'sales' => true,
                'customers' => true,
                'inventory' => true,
                'expenses' => true,
                'dashboard' => true,
                'storefront' => true,
                'pipeline' => true,
                'estimates' => true,
                'documents' => true,
                'accounting' => true,
                'hr' => true,
                'forecasting' => true,
                'marketplace' => true,
            ],
            'limits' => [
                'max_staff' => null,
                'max_products' => null,
                'max_businesses' => 5,
                'max_board_members' => null,
                'max_locations' => 5,
            ],
            'sort_order' => 3,
            'is_active' => true,
        ]);
    }
}
