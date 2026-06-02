<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'description' => fake()->sentence(),
            'price_monthly' => fake()->randomFloat(2, 0, 100000),
            'price_yearly' => fake()->randomFloat(2, 0, 1000000),
            'features' => [
                'expenses' => fake()->boolean(),
                'shift_tracking' => fake()->boolean(),
                'discounts' => fake()->boolean(),
                'refunds' => fake()->boolean(),
                'export_data' => fake()->boolean(),
            ],
            'limits' => [
                'staff_users' => fake()->numberBetween(1, 10),
                'products' => fake()->numberBetween(50, 1000),
                'monthly_sales' => fake()->numberBetween(100, 10000),
                'customers' => fake()->numberBetween(50, 1000),
                'categories' => fake()->numberBetween(5, 50),
            ],
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 10),
        ];
    }
}
