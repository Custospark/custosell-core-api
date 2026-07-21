<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::firstOrCreate(['slug' => 'essential'], [
            'name' => 'Essential',
            'slug' => 'essential',
            'description' => 'Basic POS features for small businesses just getting started.',
            'price_monthly' => 75000,
            'price_yearly' => 750000,
            'price_monthly_usd' => 20,
            'price_yearly_usd' => 200,
            'onboarding_fee_ugx' => 150000,
            'onboarding_fee_usd' => 40,
            'trial_days' => 14,
            'billing_cycle' => 'both',
            'is_popular' => false,
            'features' => [
                'sales' => true,
                'customers' => true,
                'inventory' => true,
                'expenses' => true,
                'dashboard' => true,
            ],
            'limits' => [
                'max_staff' => 3,
                'max_products' => 500,
                'max_businesses' => 1,
            ],
            'sort_order' => 1,
            'is_active' => true,
        ]);

        Plan::firstOrCreate(['slug' => 'professional'], [
            'name' => 'Professional',
            'slug' => 'professional',
            'description' => 'Full-featured POS for growing businesses with advanced tools.',
            'price_monthly' => 200000,
            'price_yearly' => 2000000,
            'price_monthly_usd' => 54,
            'price_yearly_usd' => 540,
            'onboarding_fee_ugx' => 350000,
            'onboarding_fee_usd' => 95,
            'trial_days' => 14,
            'billing_cycle' => 'both',
            'is_popular' => true,
            'features' => [
                'sales' => true,
                'customers' => true,
                'inventory' => true,
                'expenses' => true,
                'dashboard' => true,
                'pipeline' => true,
                'estimates' => true,
                'invoices' => true,
                'storefront' => true,
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

        Plan::firstOrCreate(['slug' => 'enterprise'], [
            'name' => 'Enterprise',
            'slug' => 'enterprise',
            'description' => 'Unlimited everything for large organizations and multi-branch operations.',
            'price_monthly' => 500000,
            'price_yearly' => 5000000,
            'price_monthly_usd' => 135,
            'price_yearly_usd' => 1350,
            'onboarding_fee_ugx' => 750000,
            'onboarding_fee_usd' => 200,
            'trial_days' => 7,
            'billing_cycle' => 'both',
            'is_popular' => false,
            'features' => [
                'sales' => true,
                'customers' => true,
                'inventory' => true,
                'expenses' => true,
                'dashboard' => true,
                'pipeline' => true,
                'estimates' => true,
                'invoices' => true,
                'storefront' => true,
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
