<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'category_id' => null,
            'name' => fake()->unique()->word(),
            'type' => Product::TYPE_PRODUCT,
            'description' => fake()->sentence(),
            'sku' => 'SKU-' . fake()->unique()->randomNumber(6),
            'barcode' => null,
            'unit_price' => fake()->randomFloat(2, 100, 100000),
            'cost_price' => fake()->randomFloat(2, 50, 50000),
            'stock_quantity' => fake()->numberBetween(0, 100),
            'low_stock_threshold' => 5,
            'tax_percentage' => 0,
            'is_active' => true,
        ];
    }

    public function service(): static
    {
        return $this->state(fn () => [
            'type' => Product::TYPE_SERVICE,
            'stock_quantity' => 0,
            'cost_price' => 0,
            'low_stock_threshold' => 0,
        ]);
    }
}
