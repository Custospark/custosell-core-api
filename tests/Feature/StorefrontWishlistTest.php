<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductWishlist;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontWishlistTest extends TestCase
{
    use RefreshDatabase;

    protected User $buyer;
    protected User $otherUser;
    protected Business $business;
    protected Product $product;
    protected string $buyerToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->buyer = User::factory()->create(['is_active' => true]);
        $this->buyerToken = $this->buyer->createToken('t')->plainTextToken;

        $this->otherUser = User::factory()->create(['is_active' => true]);

        $this->business = Business::factory()->create([
            'owner_id' => $this->buyer->id,
            'slug' => 'test-shop',
            'status' => 'active',
            'storefront_enabled' => true,
            'currency' => 'UGX',
        ]);

        $role = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => [
                'sales.view' => true, 'sales.create' => true,
                'inventory.view' => true, 'inventory.create' => true,
            ],
        ]);
        $this->buyer->business_id = $this->business->id;
        $this->buyer->role_id = $role->id;
        $this->buyer->save();

        $category = Category::create([
            'business_id' => $this->business->id,
            'name' => 'Items',
            'sort_order' => 1,
        ]);

        $this->product = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Test Item',
            'unit_price' => 10000,
            'is_active' => true,
            'type' => Product::TYPE_PRODUCT,
            'stock_quantity' => 10,
            'listed_for_storefront' => true,
            'storefront_listed_at' => now(),
        ]);
    }

    public function test_guest_cannot_access_wishlist(): void
    {
        $this->getJson('/api/v1/storefront/wishlist')
            ->assertUnauthorized();

        $this->postJson('/api/v1/storefront/wishlist', ['product_id' => 1])
            ->assertUnauthorized();

        $this->deleteJson('/api/v1/storefront/wishlist/1')
            ->assertUnauthorized();
    }

    public function test_buyer_can_add_product_to_wishlist(): void
    {
        $res = $this->withToken($this->buyerToken)
            ->postJson('/api/v1/storefront/wishlist', [
                'product_id' => $this->product->id,
            ]);

        $res->assertCreated()
            ->assertJson([
                'message' => 'Saved to wishlist',
            ]);

        $this->assertDatabaseHas('product_wishlists', [
            'user_id' => $this->buyer->id,
            'product_id' => $this->product->id,
        ]);
    }

    public function test_add_requires_valid_product(): void
    {
        $this->withToken($this->buyerToken)
            ->postJson('/api/v1/storefront/wishlist', [
                'product_id' => 99999,
            ])
            ->assertUnprocessable();
    }

    public function test_duplicate_add_is_idempotent(): void
    {
        ProductWishlist::create([
            'user_id' => $this->buyer->id,
            'product_id' => $this->product->id,
        ]);

        $res = $this->withToken($this->buyerToken)
            ->postJson('/api/v1/storefront/wishlist', [
                'product_id' => $this->product->id,
            ]);

        $res->assertCreated();
        $this->assertDatabaseCount('product_wishlists', 1);
    }

    public function test_buyer_can_list_wishlist(): void
    {
        ProductWishlist::create([
            'user_id' => $this->buyer->id,
            'product_id' => $this->product->id,
        ]);

        $res = $this->withToken($this->buyerToken)
            ->getJson('/api/v1/storefront/wishlist');

        $res->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'product_id', 'created_at', 'product'],
                ],
                'count',
            ]);

        $this->assertCount(1, $res->json('data'));
        $this->assertEquals(1, $res->json('count'));
        $this->assertEquals($this->product->id, $res->json('data.0.product_id'));
    }

    public function test_wishlist_excludes_other_users_items(): void
    {
        ProductWishlist::create([
            'user_id' => $this->buyer->id,
            'product_id' => $this->product->id,
        ]);
        ProductWishlist::create([
            'user_id' => $this->otherUser->id,
            'product_id' => $this->product->id,
        ]);

        $res = $this->withToken($this->buyerToken)
            ->getJson('/api/v1/storefront/wishlist');

        $this->assertCount(1, $res->json('data'));
    }

    public function test_buyer_can_remove_wishlist_item(): void
    {
        $wish = ProductWishlist::create([
            'user_id' => $this->buyer->id,
            'product_id' => $this->product->id,
        ]);

        $this->withToken($this->buyerToken)
            ->deleteJson("/api/v1/storefront/wishlist/{$wish->id}")
            ->assertOk()
            ->assertJson(['message' => 'Removed from wishlist']);

        $this->assertDatabaseMissing('product_wishlists', ['id' => $wish->id]);
    }

    public function test_cannot_remove_others_wishlist_item(): void
    {
        $wish = ProductWishlist::create([
            'user_id' => $this->otherUser->id,
            'product_id' => $this->product->id,
        ]);

        $this->withToken($this->buyerToken)
            ->deleteJson("/api/v1/storefront/wishlist/{$wish->id}")
            ->assertNotFound();
    }

    public function test_remove_non_existent_item_returns_404(): void
    {
        $this->withToken($this->buyerToken)
            ->deleteJson('/api/v1/storefront/wishlist/99999')
            ->assertNotFound();
    }

    public function test_wishlist_empty_when_no_items(): void
    {
        $res = $this->withToken($this->buyerToken)
            ->getJson('/api/v1/storefront/wishlist');

        $res->assertOk();
        $this->assertEmpty($res->json('data'));
        $this->assertEquals(0, $res->json('count'));
    }

    public function test_wishlist_product_has_full_details(): void
    {
        ProductWishlist::create([
            'user_id' => $this->buyer->id,
            'product_id' => $this->product->id,
        ]);

        $res = $this->withToken($this->buyerToken)
            ->getJson('/api/v1/storefront/wishlist');

        $product = $res->json('data.0.product');
        $this->assertNotNull($product);
        $this->assertEquals($this->product->id, $product['id']);
        $this->assertEquals($this->product->name, $product['name']);
        $this->assertEquals($this->product->unit_price, $product['unit_price']);
        $this->assertNotNull($product['business']);
        $this->assertEquals($this->business->slug, $product['business']['slug']);
    }

    public function test_place_order_removes_ordered_products_from_wishlist(): void
    {
        ProductWishlist::create([
            'user_id' => $this->buyer->id,
            'product_id' => $this->product->id,
        ]);

        $this->withToken($this->buyerToken)
            ->postJson('/api/v1/storefront/test-shop/orders', [
                'customer_name' => 'Buyer',
                'customer_phone' => '+256700000001',
                'items' => [
                    ['product_id' => $this->product->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        $this->assertDatabaseMissing('product_wishlists', [
            'user_id' => $this->buyer->id,
            'product_id' => $this->product->id,
        ]);
    }
}
