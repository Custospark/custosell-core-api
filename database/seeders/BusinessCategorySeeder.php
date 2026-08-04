<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Curated business categories for storefront filtering.
 * Idempotent by slug — safe to re-run.
 */
class BusinessCategorySeeder extends Seeder
{
    /** @var array{slug: string, name: string}[] */
    private const CATEGORIES = [
        ['slug' => 'retail', 'name' => 'Retail & General Merchandise'],
        ['slug' => 'food-dining', 'name' => 'Food, Restaurants & Dining'],
        ['slug' => 'groceries', 'name' => 'Groceries & Supermarkets'],
        ['slug' => 'health-beauty', 'name' => 'Health, Pharmacy & Beauty'],
        ['slug' => 'services', 'name' => 'Professional & Home Services'],
        ['slug' => 'agribusiness', 'name' => 'Agribusiness & Farming'],
        ['slug' => 'technology', 'name' => 'Technology & Electronics'],
        ['slug' => 'fashion', 'name' => 'Fashion & Apparel'],
        ['slug' => 'home-living', 'name' => 'Home & Living'],
        ['slug' => 'transport', 'name' => 'Transport & Logistics'],
        ['slug' => 'education', 'name' => 'Education & Training'],
        ['slug' => 'entertainment', 'name' => 'Entertainment & Events'],
        ['slug' => 'finance', 'name' => 'Financial & Insurance'],
        ['slug' => 'construction', 'name' => 'Construction & Hardware'],
        ['slug' => 'automotive', 'name' => 'Automotive & Parts'],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $i => $category) {
            DB::table('business_categories')->updateOrInsert(
                ['slug' => $category['slug']],
                [
                    'name' => $category['name'],
                    'sort_order' => $i,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }
}