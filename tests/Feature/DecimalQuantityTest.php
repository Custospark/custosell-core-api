<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Location;
use App\Models\LocationProduct;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecimalQuantityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $adminToken;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->adminToken = $this->admin->createToken('admin')->plainTextToken;

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
            'permissions' => [
                'sales.create' => true, 'sales.view' => true, 'sales.refund' => true,
                'inventory.view' => true, 'inventory.create' => true,
                'customers.view' => true, 'customers.create' => true,
            ],
        ]);
        $this->admin->role_id = $role->id;
        $this->admin->save();

        Location::create([
            'business_id' => $this->business->id,
            'name' => 'Main',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->setUpSubscription();
    }

    protected function seedLocationStock(Product $product, float $qty): void
    {
        $location = Location::forBusiness($this->business->id)->where('is_default', true)->firstOrFail();
        LocationProduct::updateOrCreate(
            [
                'business_id' => $this->business->id,
                'location_id' => $location->id,
                'product_id' => $product->id,
            ],
            ['stock_quantity' => $qty, 'low_stock_threshold' => (int) ($product->low_stock_threshold ?? 0)],
        );
    }

    public function test_decimal_quantity_sale_calculates_price_and_deducts_stock(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Sugar',
            'unit' => 'Kg',
            'pricing_unit' => 'kg',
            'unit_price' => 4000,
            'cost_price' => 3000,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $this->seedLocationStock($product, 10);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/sales', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 0.5, 'unit_price' => 4000],
                ],
                'subtotal' => 2000,
                'total_amount' => 2000,
                'payment_method' => 'cash',
                'sale_date' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $response->json('data.id'),
            'product_id' => $product->id,
            'quantity' => 0.5,
        ]);

        $this->assertEqualsWithDelta(2000, (float) $response->json('data.subtotal'), 0.01);
        $this->assertEqualsWithDelta(9.5, (float) $product->fresh()->stock_quantity, 0.001);
    }

    public function test_custom_unit_product_still_sells_and_stocks_as_integer(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Cable Reel',
            'unit' => 'Roll',
            'unit_price' => 5000,
            'cost_price' => 4000,
            'stock_quantity' => 6,
            'is_active' => true,
        ]);

        $this->seedLocationStock($product, 6);

        // Unit "Roll" is not in the configured list -> treated as a piece/integer unit.
        $this->assertFalse(\App\Support\PricingUnits::supportsDecimalQuantity($product->unit));

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/sales', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 5000],
                ],
                'subtotal' => 10000,
                'total_amount' => 10000,
                'payment_method' => 'cash',
                'sale_date' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(201);
        $this->assertEqualsWithDelta(4.0, (float) $product->fresh()->stock_quantity, 0.001);
    }

    public function test_decimal_quantity_refund_restores_fractional_stock(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Flour',
            'unit' => 'Kg',
            'pricing_unit' => 'kg',
            'unit_price' => 3000,
            'cost_price' => 2000,
            'stock_quantity' => 20,
            'is_active' => true,
        ]);

        $this->seedLocationStock($product, 20);

        $saleResponse = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/sales', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1.5, 'unit_price' => 3000],
                ],
                'subtotal' => 4500,
                'total_amount' => 4500,
                'payment_method' => 'cash',
                'sale_date' => now()->toDateTimeString(),
            ]);

        $saleResponse->assertStatus(201);
        $saleId = $saleResponse->json('data.id');
        $saleItem = \App\Models\SaleItem::where('sale_id', $saleId)->firstOrFail();
        $stockAfterSale = (float) $product->fresh()->stock_quantity;

        $refundResponse = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson("/api/v1/sales/{$saleId}/refund", [
                'items' => [
                    ['id' => $saleItem->id, 'quantity' => 0.5],
                ],
            ]);

        $refundResponse->assertStatus(200);
        $this->assertEqualsWithDelta($stockAfterSale + 0.5, (float) $product->fresh()->stock_quantity, 0.001);
        $this->assertEqualsWithDelta(0.5, (float) $saleItem->fresh()->refunded_quantity, 0.001);
    }

    public function test_pricing_unit_resource_flags_decimal_capability(): void
    {
        $sugar = Product::factory()->create([
            'business_id' => $this->business->id,
            'unit' => 'Kg',
            'pricing_unit' => 'kg',
        ]);
        $custom = Product::factory()->create([
            'business_id' => $this->business->id,
            'unit' => 'Roll',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/products');

        $response->assertStatus(200);
        $data = collect($response->json('data'))->keyBy('id');

        $this->assertTrue($data[$sugar->id]['supports_decimal_quantity']);
        $this->assertFalse($data[$custom->id]['supports_decimal_quantity']);
        $this->assertSame('Roll', $data[$custom->id]['pricing_unit_label']);
    }

    protected function tearDown(): void
    {
        Sale::query()->delete();
        parent::tearDown();
    }
}