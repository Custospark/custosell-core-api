<?php

namespace Tests\Unit\Services;

use App\Models\Business;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryLedgerService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected InventoryLedgerService $service;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create(['owner_id' => $user->id]);
        $this->service = app(InventoryLedgerService::class);
    }

    public function test_excludes_unrealistic_stock_from_book_value(): void
    {
        $good = Product::factory()->create([
            'business_id' => $this->business->id,
            'stock_quantity' => 10,
            'cost_price' => 100,
            'is_active' => true,
        ]);

        $outlier = Product::factory()->create([
            'business_id' => $this->business->id,
            'stock_quantity' => 9_999_999,
            'cost_price' => 500,
            'is_active' => true,
        ]);

        foreach ([$good, $outlier] as $product) {
            StockMovement::create([
                'business_id' => $this->business->id,
                'product_id' => $product->id,
                'type' => 'adjustment',
                'quantity_change' => (int) $product->stock_quantity,
                'stock_before' => 0,
                'stock_after' => (int) $product->stock_quantity,
                'notes' => 'Test seed',
            ]);
        }

        $analysis = $this->service->analyzeStock($this->business->id);

        $this->assertEquals(1000.0, $analysis['included_value']);
        $this->assertEquals(1, $analysis['included_count']);
        $this->assertEquals(1, $analysis['excluded_count']);
    }
}
