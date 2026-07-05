<?php

namespace Tests\Unit\Services;

use App\Models\AccountType;
use App\Models\Business;
use App\Models\ChartOfAccount;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\InventoryCogsService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryCogsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected InventoryCogsService $service;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create(['owner_id' => $user->id]);
        $this->service = app(InventoryCogsService::class);
    }

    public function test_sale_cogs_uses_net_quantity_after_refunds(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'cost_price' => 100,
            'stock_quantity' => 10,
        ]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->business->owner_id,
            'receipt_number' => 'TEST-001',
            'subtotal' => 800,
            'tax_total' => 0,
            'discount_amount' => 0,
            'total_amount' => 800,
            'amount_paid' => 800,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'sale_date' => now(),
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 200,
            'quantity' => 4,
            'unit_price' => 200,
            'unit_cost' => 100,
            'subtotal' => 800,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'refunded_quantity' => 1,
        ]);

        $this->assertEquals(300.0, $this->service->calculateSaleCogs($sale));
    }

    public function test_invoice_cogs_is_zero_for_billing_documents(): void
    {
        $invoice = new \App\Models\Invoice(['business_id' => $this->business->id]);
        $this->assertEquals(0.0, $this->service->calculateInvoiceCogs($invoice));
    }
}
