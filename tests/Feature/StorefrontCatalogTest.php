<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Business;

class StorefrontCatalogTest extends StorefrontTestCase
{
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

    public function test_discover_products_include_stock_fields(): void
    {
        $res = $this->getJson('/api/v1/storefront/discover');
        $res->assertOk();

        $item = collect($res->json('data'))->firstWhere('name', 'Chicken Plate');
        $this->assertNotNull($item);
        $this->assertSame(25, (int) $item['stock_quantity']);
        $this->assertTrue($item['in_stock']);
        $this->assertSame('in_stock', $item['availability']);
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

    public function test_shops_search_matches_slug_with_or_without_at(): void
    {
        $bySlug = $this->getJson('/api/v1/storefront/shops?q=devine-mercy-restaurant');
        $bySlug->assertOk();
        $this->assertContains('devine-mercy-restaurant', collect($bySlug->json('data'))->pluck('slug')->all());

        $byAt = $this->getJson('/api/v1/storefront/shops?q=%40devine-mercy');
        $byAt->assertOk();
        $this->assertContains('devine-mercy-restaurant', collect($byAt->json('data'))->pluck('slug')->all());
    }

    public function test_shops_include_contact_fields(): void
    {
        $this->business->update([
            'description' => 'Fresh meals daily',
            'city' => 'Kampala',
            'country' => 'Uganda',
            'address' => 'Plot 1 Kampala Road',
            'business_phone' => '+256700111222',
            'business_email' => 'hello@devine.test',
        ]);

        $res = $this->getJson('/api/v1/storefront/shops');
        $res->assertOk();
        $shop = collect($res->json('data'))->firstWhere('slug', $this->business->slug);
        $this->assertNotNull($shop);
        $this->assertSame('Fresh meals daily', $shop['description']);
        $this->assertSame('Plot 1 Kampala Road', $shop['address']);
        $this->assertSame('+256700111222', $shop['business_phone']);
        $this->assertSame('hello@devine.test', $shop['business_email']);
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

    public function test_shop_visible_for_active_and_hidden_when_suspended(): void
    {
        $this->getJson('/api/v1/storefront/devine-mercy-restaurant')
            ->assertOk()
            ->assertJsonPath('slug', 'devine-mercy-restaurant');

        // SQLite test schema only allows active|suspended; production also has warning/notified
        // which remain public via publicStorefront() (blocked list = restricted/suspended).
        $blocked = config('platform.blocked_business_statuses', ['restricted', 'suspended']);
        $this->assertContains('suspended', $blocked);
        $this->assertContains('restricted', $blocked);
        $this->assertNotContains('warning', $blocked);
        $this->assertNotContains('notified', $blocked);

        $this->business->update(['status' => 'suspended']);
        $this->getJson('/api/v1/storefront/devine-mercy-restaurant')
            ->assertNotFound();

        $this->assertNull(
            Business::query()->publicStorefront()->whereKey($this->business->id)->first()
        );
    }
}
