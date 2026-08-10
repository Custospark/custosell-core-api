<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Location;
use App\Models\LocationProduct;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Business $business;

    protected string $token;

    protected Location $main;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->admin->business_id = $this->business->id;
        $this->admin->save();

        $role = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => ['inventory.view' => true, 'inventory.create' => true],
        ]);
        $this->admin->role_id = $role->id;
        $this->admin->save();
        $this->token = $this->admin->createToken('admin')->plainTextToken;

        $this->setUpSubscription();

        $this->main = Location::create([
            'business_id' => $this->business->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    public function test_overview_aggregates_stock_value_at_cost_with_projected_profit(): void
    {
        $drinks = Category::create(['business_id' => $this->business->id, 'name' => 'Drinks']);
        $snacks = Category::create(['business_id' => $this->business->id, 'name' => 'Snacks']);

        $soda = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $drinks->id,
            'name' => 'Soda',
            'unit_price' => 5000,
            'wholesale_price' => 4000,
            'cost_price' => 2000,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $chips = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $snacks->id,
            'name' => 'Chips',
            'unit_price' => 3000,
            'wholesale_price' => 2500,
            'cost_price' => 1000,
            'stock_quantity' => 4,
            'is_active' => true,
        ]);
        $service = Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Repair',
            'type' => 'service',
            'unit_price' => 20000,
            'cost_price' => 0,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        foreach ([$soda, $chips, $service] as $product) {
            LocationProduct::create([
                'business_id' => $this->business->id,
                'location_id' => $this->main->id,
                'product_id' => $product->id,
                'stock_quantity' => $product->stock_quantity,
                'low_stock_threshold' => $product->low_stock_threshold ?? 0,
            ]);
            StockMovement::create([
                'business_id' => $this->business->id,
                'location_id' => $this->main->id,
                'product_id' => $product->id,
                'type' => 'adjustment',
                'quantity_change' => (int) $product->stock_quantity,
                'stock_before' => 0,
                'stock_after' => (int) $product->stock_quantity,
                'notes' => 'seed',
            ]);
        }

        $response = $this->withHeader('Authorization', "Bearer $this->token")
            ->getJson('/api/v1/inventory/overview');

        $response->assertStatus(200);

        $summary = $response->json('summary');

        // Soda: 10×2000, Chips: 4×1000; service excluded (no stock, no cost).
        $this->assertEquals(24000, $summary['value_cost']);
        $this->assertEquals(10 * 5000 + 4 * 3000, $summary['value_retail']);
        $this->assertEquals(10 * 4000 + 4 * 2500, $summary['value_wholesale']);
        $this->assertEquals(14, $summary['stock_quantity']);
        $this->assertEquals(2, $summary['stocked_product_count']);
        $this->assertEquals(2, $summary['product_count']);

        $byCategory = collect($response->json('by_category'));
        $this->assertEquals(2, $byCategory->count());
        $this->assertTrue($byCategory->contains('category_name', 'Drinks'));
        $this->assertTrue($byCategory->contains('category_name', 'Snacks'));

        $byBranch = $response->json('by_branch');
        $this->assertCount(1, $byBranch);
        $this->assertEquals('Main Branch', $byBranch[0]['location_name']);
        $this->assertEquals(24000, $byBranch[0]['value_cost']);
        $this->assertEquals(100.0, $byBranch[0]['share_pct']);

        $trend = $response->json('trend');
        $this->assertCount(12, $trend);
        $this->assertEquals(24000, collect($trend)->last()['value_cost']);
    }

    public function test_overview_highlights_low_stock_out_of_stock_and_valuation_profit_pct(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Nearly Gone',
            'unit_price' => 1000,
            'cost_price' => 500,
            'stock_quantity' => 2,
            'low_stock_threshold' => 5,
            'is_active' => true,
        ]);
        Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Empty',
            'unit_price' => 1000,
            'cost_price' => 500,
            'stock_quantity' => 0,
            'low_stock_threshold' => 3,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
            ->getJson('/api/v1/inventory/overview');

        $response->assertStatus(200);

        $summary = $response->json('summary');
        $this->assertEquals(1, $summary['low_stock_count']);
        $this->assertEquals(1, $summary['out_of_stock_count']);
        $this->assertEquals(1, $summary['stocked_product_count']);
        $this->assertEquals(100.0, $summary['profit_retail_pct']);
    }

    public function test_overview_scopes_to_a_branch(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Scoped',
            'unit_price' => 2000,
            'cost_price' => 1000,
            'stock_quantity' => 6,
            'is_active' => true,
        ]);

        $other = Location::create([
            'business_id' => $this->business->id,
            'name' => 'Branch 2',
            'code' => 'B2',
            'is_active' => true,
        ]);

        LocationProduct::create([
            'business_id' => $this->business->id,
            'location_id' => $this->main->id,
            'product_id' => $product->id,
            'stock_quantity' => 6,
            'low_stock_threshold' => 0,
        ]);
        LocationProduct::create([
            'business_id' => $this->business->id,
            'location_id' => $other->id,
            'product_id' => $product->id,
            'stock_quantity' => 2,
            'low_stock_threshold' => 0,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->token")
            ->getJson("/api/v1/inventory/overview?location_id={$other->id}");

        $response->assertStatus(200)
            ->assertJsonPath('location_id', $other->id)
            ->assertJsonPath('summary.value_cost', 2 * 1000)
            ->assertJsonPath('summary.stock_quantity', 2);
    }

    public function test_overview_trend_scopes_to_a_branch(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Trend Item',
            'unit_price' => 3000,
            'cost_price' => 1000,
            'stock_quantity' => 20,
            'is_active' => true,
        ]);

        $branchB = Location::create([
            'business_id' => $this->business->id,
            'name' => 'Branch B',
            'code' => 'B',
            'is_active' => true,
        ]);

        foreach ([$this->main, $branchB] as $i => $location) {
            $qty = $i === 0 ? 20 : 8;
            LocationProduct::create([
                'business_id' => $this->business->id,
                'location_id' => $location->id,
                'product_id' => $product->id,
                'stock_quantity' => $qty,
                'low_stock_threshold' => 0,
            ]);
            StockMovement::create([
                'business_id' => $this->business->id,
                'location_id' => $location->id,
                'product_id' => $product->id,
                'type' => 'adjustment',
                'quantity_change' => $qty,
                'stock_before' => 0,
                'stock_after' => $qty,
                'notes' => 'seed',
            ]);
        }

        $all = $this->withHeader('Authorization', "Bearer $this->token")
            ->getJson('/api/v1/inventory/overview')
            ->json('trend');
        $scoped = $this->withHeader('Authorization', "Bearer $this->token")
            ->getJson("/api/v1/inventory/overview?location_id={$branchB->id}")
            ->json('trend');

        // 20 (main) + 8 (branch B) across every bucket unscoped.
        $this->assertSame(28, (int) collect($all)->last()['stock_quantity']);
        $this->assertSame(28000.0, (float) collect($all)->last()['value_cost']);

        // Scoped to branch B only: 8 units = 8 × 1000 cost.
        $this->assertSame(8, (int) collect($scoped)->last()['stock_quantity']);
        $this->assertSame(8000.0, (float) collect($scoped)->last()['value_cost']);
    }
}