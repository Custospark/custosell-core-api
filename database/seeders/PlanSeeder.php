<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::create([
            'name' => 'Free',
            'slug' => 'free',
            'description' => 'For small vendors testing the waters',
            'price_monthly' => 0,
            'price_yearly' => null,
            'features' => [
                'expenses' => false,
                'shift_tracking' => false,
                'discounts' => false,
                'refunds' => false,
                'export_data' => false,
            ],
            'limits' => [
                'staff_users' => 1,
                'products' => 50,
                'monthly_sales' => 100,
                'customers' => 50,
                'categories' => 5,
            ],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Plan::create([
            'name' => 'Pro',
            'slug' => 'pro',
            'description' => 'For most shops, salons, and pharmacies',
            'price_monthly' => 30000,
            'price_yearly' => 300000,
            'features' => [
                'expenses' => true,
                'shift_tracking' => true,
                'discounts' => true,
                'refunds' => true,
                'export_data' => false,
            ],
            'limits' => [
                'staff_users' => 5,
                'products' => 1000,
                'monthly_sales' => null,
                'customers' => 1000,
                'categories' => 30,
            ],
            'is_active' => true,
            'sort_order' => 2,
        ]);

        Plan::create([
            'name' => 'Premium',
            'slug' => 'premium',
            'description' => 'For growing businesses with multiple staff',
            'price_monthly' => 100000,
            'price_yearly' => 1000000,
            'features' => [
                'expenses' => true,
                'shift_tracking' => true,
                'discounts' => true,
                'refunds' => true,
                'export_data' => true,
            ],
            'limits' => [
                'staff_users' => null,
                'products' => null,
                'monthly_sales' => null,
                'customers' => null,
                'categories' => null,
            ],
            'is_active' => true,
            'sort_order' => 3,
        ]);
    }
}
