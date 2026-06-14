<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $this->upsertPlan('Free', 'free', 'For small vendors testing the waters', 0, null, [
            'expenses' => false,
            'shift_tracking' => false,
            'discounts' => false,
            'refunds' => false,
            'export_data' => false,
        ], [
            'staff_users' => 1,
            'products' => 50,
            'monthly_sales' => 100,
            'customers' => 50,
            'categories' => 5,
        ], true, 1);

        $this->upsertPlan('Pro', 'pro', 'For most shops, salons, and pharmacies', 30000, 300000, [
            'expenses' => true,
            'shift_tracking' => true,
            'discounts' => true,
            'refunds' => true,
            'export_data' => false,
        ], [
            'staff_users' => 5,
            'products' => 1000,
            'monthly_sales' => null,
            'customers' => 1000,
            'categories' => 30,
        ], true, 2);

        $this->upsertPlan('Premium', 'premium', 'For growing businesses with multiple staff', 100000, 1000000, [
            'expenses' => true,
            'shift_tracking' => true,
            'discounts' => true,
            'refunds' => true,
            'export_data' => true,
        ], [
            'staff_users' => null,
            'products' => null,
            'monthly_sales' => null,
            'customers' => null,
            'categories' => null,
        ], true, 3);
    }

    private function upsertPlan(string $name, string $slug, string $description, int $priceMonthly, ?int $priceYearly, array $features, array $limits, bool $isActive, int $sortOrder): void
    {
        Plan::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'description' => $description,
                'price_monthly' => $priceMonthly,
                'price_yearly' => $priceYearly,
                'features' => $features,
                'limits' => $limits,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
            ],
        );
    }
}
