<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class StorefrontTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Business $business;

    protected Product $listed;

    protected Product $unlisted;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->token = $this->owner->createToken('t')->plainTextToken;
        $this->business = Business::factory()->create([
            'owner_id' => $this->owner->id,
            'slug' => 'devine-mercy-restaurant',
            'status' => 'active',
            'storefront_enabled' => true,
            'currency' => 'UGX',
        ]);
        $this->owner->business_id = $this->business->id;
        $this->owner->save();

        $role = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => [
                'sales.view' => true, 'sales.create' => true,
                'inventory.view' => true, 'inventory.create' => true,
                'settings.view' => true, 'settings.edit' => true,
            ],
        ]);
        $this->owner->role_id = $role->id;
        $this->owner->save();

        $category = Category::create([
            'business_id' => $this->business->id,
            'name' => 'Meals',
            'sort_order' => 1,
        ]);

        $this->listed = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Chicken Plate',
            'unit_price' => 15000,
            'is_active' => true,
            'type' => Product::TYPE_PRODUCT,
            'stock_quantity' => 25,
            'listed_for_storefront' => true,
            'storefront_listed_at' => now(),
        ]);

        $this->unlisted = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Hidden Item',
            'unit_price' => 5000,
            'is_active' => true,
            'listed_for_storefront' => false,
        ]);
    }
}
