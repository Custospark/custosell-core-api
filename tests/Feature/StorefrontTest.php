<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontTest extends TestCase
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

    public function test_shop_404_when_storefront_disabled(): void
    {
        $this->business->update(['storefront_enabled' => false]);

        $this->getJson('/api/v1/storefront/devine-mercy-restaurant')
            ->assertNotFound();
    }

    public function test_discover_lists_only_storefront_products(): void
    {
        $res = $this->getJson('/api/v1/storefront/discover');
        $res->assertOk();
        $names = collect($res->json('data'))->pluck('name')->all();
        $this->assertContains('Chicken Plate', $names);
        $this->assertNotContains('Hidden Item', $names);
    }

    public function test_guest_can_place_storefront_order(): void
    {
        $res = $this->postJson('/api/v1/storefront/devine-mercy-restaurant/orders', [
            'customer_name' => 'Amina',
            'customer_phone' => '+256700000001',
            'notes' => 'Extra sauce',
            'items' => [
                ['product_id' => $this->listed->id, 'quantity' => 2],
            ],
        ]);

        $res->assertCreated()
            ->assertJsonPath('order.source', 'storefront')
            ->assertJsonPath('order.customer_phone', '+256700000001');

        $order = Order::query()->first();
        $this->assertNotNull($order);
        $this->assertSame('storefront', $order->source);
        $this->assertSame($this->owner->id, $order->user_id);
        $this->assertSame(Order::STATUS_OPEN, $order->status);
    }

    public function test_rejects_unlisted_product_on_order(): void
    {
        $this->postJson('/api/v1/storefront/devine-mercy-restaurant/orders', [
            'customer_name' => 'Amina',
            'customer_phone' => '+256700000001',
            'items' => [
                ['product_id' => $this->unlisted->id, 'quantity' => 1],
            ],
        ])->assertStatus(422);
    }

    public function test_slug_available_endpoint(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/v1/businesses/slug-available?slug=new-cafe')
            ->assertOk()
            ->assertJsonPath('available', true);

        $this->withToken($this->token)
            ->getJson('/api/v1/businesses/slug-available?slug=devine-mercy-restaurant')
            ->assertOk()
            ->assertJsonPath('available', true); // same business ignored
    }
}
