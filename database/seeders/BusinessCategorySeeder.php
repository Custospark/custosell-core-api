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
        ['slug' => 'wholesale', 'name' => 'Wholesale & Distribution'],
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
        ['slug' => 'beauty-salon', 'name' => 'Beauty Salons & Barbershops'],
        ['slug' => 'cleaning', 'name' => 'Cleaning & Laundry'],
        ['slug' => 'printing', 'name' => 'Printing & Stationery'],
        ['slug' => 'furniture', 'name' => 'Furniture & Interior'],
        ['slug' => 'electronics', 'name' => 'Electronics & Appliances'],
        ['slug' => 'pharmacy', 'name' => 'Pharmacies & Clinics'],
        ['slug' => 'lodge-hotel', 'name' => 'Lodges & Hotels'],
        ['slug' => 'travel-tours', 'name' => 'Travel & Tours'],
        ['slug' => 'mechanics', 'name' => 'Mechanics & Auto Services'],
        ['slug' => 'carpentry', 'name' => 'Carpentry & Woodworking'],
        ['slug' => 'tailoring', 'name' => 'Tailoring & Fashion Design'],
        ['slug' => 'barbershop', 'name' => 'Barbershops & Grooming'],
        ['slug' => 'bakery', 'name' => 'Bakeries & Confectionery'],
        ['slug' => 'butchery', 'name' => 'Butcheries & Meat Products'],
        ['slug' => 'poultry', 'name' => 'Poultry & Livestock'],
        ['slug' => 'fisheries', 'name' => 'Fisheries & Fish Farming'],
        ['slug' => 'florist', 'name' => 'Florists & Events'],
        ['slug' => 'gifts', 'name' => 'Gifts & Souvenirs'],
        ['slug' => 'fitness', 'name' => 'Fitness & Wellness'],
        ['slug' => 'security', 'name' => 'Security & Surveillance'],
        ['slug' => 'language-schools', 'name' => 'Training & Vocational Schools'],
        ['slug' => 'nursery-schools', 'name' => 'Schools & Daycares'],
        ['slug' => 'solar-energy', 'name' => 'Solar & Renewable Energy'],
        ['slug' => 'water-supply', 'name' => 'Water Supply & Purification'],
        ['slug' => 'liquor-store', 'name' => 'Liquor & Beverage Stores'],
        ['slug' => 'pharmacy-equipment', 'name' => 'Medical & Laboratory Supplies'],
        ['slug' => 'hardware', 'name' => 'Hardware & Building Materials'],
        ['slug' => 'telecom', 'name' => 'Telecom & Mobile Services'],
        ['slug' => 'arts-crafts', 'name' => 'Arts, Crafts & Studios'],
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