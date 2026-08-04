<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\User;
use Database\Seeders\BusinessCategorySeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Both the business profile/settings page (category picker) and the storefront
 * filter bar load their options from the SAME source: GET /storefront/facets,
 * keyed under `data.business_categories`.
 *
 * These tests pin the contract that the seeded category list ALWAYS loads,
 * independent of how many (if any) storefront-enabled businesses exist.
 */
class StorefrontFacetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->seed(BusinessCategorySeeder::class);
    }

    private function seededCategoryCount(): int
    {
        return BusinessCategory::query()->count();
    }

    public function test_facets_return_all_seeded_categories_for_settings_picker(): void
    {
        // A business owner configuring their profile may have storefront disabled
        // and not yet chosen a category. The full list must still be returned so
        // the Settings > Business category picker has options to select from.
        $owner = User::factory()->create(['is_active' => true]);
        $token = $owner->createToken('t')->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/storefront/facets');

        $res->assertOk();
        $categories = $res->json('data.business_categories');
        $this->assertIsArray($categories);
        $this->assertNotEmpty($categories);
        $this->assertCount($this->seededCategoryCount(), $categories);

        // Each option must carry the fields the Settings picker needs to save.
        $this->assertArrayHasKey('id', $categories[0]);
        $this->assertArrayHasKey('slug', $categories[0]);
        $this->assertArrayHasKey('name', $categories[0]);
        $this->assertArrayHasKey('count', $categories[0]);
        $this->assertIsInt($categories[0]['id']);
    }

    public function test_facets_return_all_seeded_categories_for_filter_bar_without_any_businesses(): void
    {
        // Public storefront browsing with zero businesses (fresh/no data). The
        // filter bar must still render every seeded Business type chip option.
        $res = $this->getJson('/api/v1/storefront/facets');

        $res->assertOk();
        $categories = $res->json('data.business_categories');
        $this->assertIsArray($categories);
        $this->assertNotEmpty($categories);
        $this->assertCount($this->seededCategoryCount(), $categories);

        $slugs = collect($categories)->pluck('slug')->all();
        $this->assertContains('retail', $slugs);
        $this->assertContains('food-dining', $slugs);
    }

    public function test_facets_count_only_storefront_enabled_businesses_per_category(): void
    {
        // A storefront-enabled business assigned to a category must be counted
        // for that category, while a non-storefront (or unassigned) business
        // must not inflate it, and every category still appears.
        $enabledCat = BusinessCategory::where('slug', 'retail')->first();
        $disabledCat = BusinessCategory::where('slug', 'food-dining')->first();

        Business::factory()->create([
            'status' => 'active',
            'storefront_enabled' => true,
            'business_category_id' => $enabledCat->id,
        ]);
        Business::factory()->create([
            'status' => 'active',
            'storefront_enabled' => false,
            'business_category_id' => $enabledCat->id,
        ]);
        Business::factory()->create([
            'status' => 'suspended',
            'storefront_enabled' => true,
            'business_category_id' => $enabledCat->id,
        ]);
        Business::factory()->create([
            'status' => 'active',
            'storefront_enabled' => true,
            'business_category_id' => $disabledCat->id,
        ]);

        $res = $this->getJson('/api/v1/storefront/facets');
        $res->assertOk();

        $categories = collect($res->json('data.business_categories'));
        $this->assertCount($this->seededCategoryCount(), $categories);

        $retail = $categories->firstWhere('slug', 'retail');
        $food = $categories->firstWhere('slug', 'food-dining');
        $this->assertNotNull($retail);
        $this->assertNotNull($food);

        // Only the single active + storefront_enabled business counts.
        $this->assertSame(1, (int) $retail['count']);
        $this->assertSame(1, (int) $food['count']);
    }
}