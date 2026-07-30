<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::updateOrCreate(['slug' => 'essential'], [
            'name' => 'Essential',
            'slug' => 'essential',
            'description' => 'Point of sale, inventory, and online storefront for small businesses.',
            'price_monthly_usd' => 20,
            'price_yearly_usd' => 200,
            'onboarding_fee_usd' => 40,
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
            ],
            'sort_order' => 1,
            'is_active' => true,
        ]);

        Plan::updateOrCreate(['slug' => 'professional'], [
            'name' => 'Professional',
            'slug' => 'professional',
            'description' => 'Full business management suite for growing businesses — including pipeline, estimates, and document management.',
            'price_monthly_usd' => 54,
            'price_yearly_usd' => 540,
            'onboarding_fee_usd' => 95,
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
                'max_businesses' => 1,
            ],
            'sort_order' => 2,
            'is_active' => true,
        ]);

        Plan::updateOrCreate(['slug' => 'pipeline-personal'], [
            'name' => 'Pipeline (Personal)',
            'slug' => 'pipeline-personal',
            'description' => 'Project management for individuals — boards, tasks, leads, and insights.',
            'price_monthly_usd' => 5,
            'price_yearly_usd' => 50,
            'onboarding_fee_usd' => 0,
            'trial_days' => 14,
            'billing_cycle' => 'both',
            'is_popular' => false,
            'features' => [
                'pipeline' => true,
            ],
            'limits' => [],
            'sort_order' => 10,
            'is_active' => true,
        ]);

        Plan::updateOrCreate(['slug' => 'accounting-personal'], [
            'name' => 'Accounting (Personal)',
            'slug' => 'accounting-personal',
            'description' => 'Personal bookkeeping — chart of accounts, journal entries, and financial reports.',
            'price_monthly_usd' => 5,
            'price_yearly_usd' => 50,
            'onboarding_fee_usd' => 0,
            'trial_days' => 14,
            'billing_cycle' => 'both',
            'is_popular' => false,
            'features' => [
                'accounting' => true,
            ],
            'limits' => [],
            'sort_order' => 11,
            'is_active' => true,
        ]);

        Plan::updateOrCreate(['slug' => 'enterprise'], [
            'name' => 'Enterprise',
            'slug' => 'enterprise',
            'description' => 'Unlimited everything for large organizations and multi-branch operations.',
            'price_monthly_usd' => 135,
            'price_yearly_usd' => 1350,
            'onboarding_fee_usd' => 200,
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
            ],
            'sort_order' => 3,
            'is_active' => true,
        ]);
    }
}
