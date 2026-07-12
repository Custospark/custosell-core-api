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

    public function test_shops_lists_enabled_shop_even_without_listed_products(): void
    {
        $emptyEnabled = Business::factory()->create([
            'name' => 'Empty Enabled Cafe',
            'slug' => 'empty-enabled-cafe',
            'status' => 'active',
            'storefront_enabled' => true,
        ]);

        $disabled = Business::factory()->create([
            'name' => 'Disabled Shop',
            'slug' => 'disabled-shop',
            'status' => 'active',
            'storefront_enabled' => false,
        ]);

        $res = $this->getJson('/api/v1/storefront/shops');
        $res->assertOk();

        $slugs = collect($res->json('data'))->pluck('slug')->all();
        $this->assertContains($this->business->slug, $slugs);
        $this->assertContains($emptyEnabled->slug, $slugs);
        $this->assertNotContains($disabled->slug, $slugs);
        $this->assertArrayHasKey('meta', $res->json());
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
        $this->assertNull($order->storefront_buyer_user_id);
    }

    public function test_authenticated_buyer_sees_own_storefront_orders(): void
    {
        $buyer = User::factory()->create(['is_active' => true]);
        $buyerToken = $buyer->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/orders', [
                'customer_name' => 'Buyer User',
                'customer_phone' => '+256700000099',
                'items' => [
                    ['product_id' => $this->listed->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        $res = $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->getJson('/api/v1/storefront/my-orders');

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($this->business->slug, $res->json('data.0.shop_slug'));
        $this->assertSame($this->business->name, $res->json('data.0.shop_name'));
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
