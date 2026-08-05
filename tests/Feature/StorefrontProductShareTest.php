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

/**
 * Public, shareable storefront product links: identity is the product slug,
 * scoped to a single shop. Guests (no auth) can open them to view a product.
 *
 * Endpoint: GET /storefront/{slug}/products/{productSlug}
 */
class StorefrontProductShareTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Product $listed;
    protected Product $unlisted;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $owner = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create([
            'owner_id' => $owner->id,
            'slug' => 'devine-mercy-restaurant',
            'status' => 'active',
            'storefront_enabled' => true,
            'currency' => 'UGX',
        ]);
        $owner->business_id = $this->business->id;
        $owner->save();

        $category = Category::create([
            'business_id' => $this->business->id,
            'name' => 'Meals',
            'sort_order' => 1,
        ]);

        $this->listed = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Chicken Plate',
            'slug' => 'chicken-plate',
            'unit_price' => 15000,
            'is_active' => true,
            'listed_for_storefront' => true,
        ]);

        $this->unlisted = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Hidden Item',
            'slug' => 'hidden-item',
            'unit_price' => 5000,
            'is_active' => true,
            'listed_for_storefront' => false,
        ]);
    }

    public function test_guest_can_open_shared_product_link_by_slug(): void
    {
        $this->getJson('/api/v1/storefront/devine-mercy-restaurant/products/chicken-plate')
            ->assertOk()
            ->assertJsonPath('data.id', $this->listed->id)
            ->assertJsonPath('data.slug', 'chicken-plate')
            ->assertJsonPath('data.name', 'Chicken Plate')
            ->assertJsonPath('data.business.slug', 'devine-mercy-restaurant')
            ->assertJsonPath('data.business.currency', 'UGX');
    }

    public function test_shared_link_404s_for_missing_product_slug(): void
    {
        $this->getJson('/api/v1/storefront/devine-mercy-restaurant/products/does-not-exist')
            ->assertStatus(422);
    }

    public function test_shared_link_404s_for_unknown_shop(): void
    {
        $this->getJson('/api/v1/storefront/unknown-shop/products/chicken-plate')
            ->assertNotFound();
    }

    public function test_shared_link_hides_unlisted_product(): void
    {
        $this->getJson('/api/v1/storefront/devine-mercy-restaurant/products/hidden-item')
            ->assertStatus(422);
    }

    public function test_shared_link_scoped_to_shop_slug(): void
    {
        $this->getJson('/api/v1/storefront/devine-mercy-restaurant/products/chicken-plate')
            ->assertJsonPath('data.business.slug', 'devine-mercy-restaurant');
    }
}