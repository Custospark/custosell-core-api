<?php

namespace Tests\Feature;

use App\Events\InvoiceSentForAccounting;
use App\Models\Business;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\Role;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsAccounting;
use Tests\TestCase;

class ProductServiceSalesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAccounting;

    protected User $admin;

    protected Business $business;

    protected string $adminToken;

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

        $adminRole = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => [
                'sales.create' => true, 'sales.view' => true, 'sales.refund' => true,
                'inventory.view' => true, 'inventory.create' => true,
                'customers.view' => true, 'customers.create' => true,
                'expenses.view' => true, 'expenses.create' => true,
                'users.view' => true, 'users.create' => true,
                'reports.view' => true, 'settings.view' => true, 'settings.edit' => true,
                'accounting.view' => true,
            ],
        ]);
        $this->admin->role_id = $adminRole->id;
        $this->admin->save();

        $this->seedAccountingForBusiness($this->business);
    }

    public function test_sale_of_service_with_stock_zero_succeeds(): void
    {
        $service = Product::factory()->service()->create([
            'business_id' => $this->business->id,
            'unit_price' => 5000,
            'stock_quantity' => 0,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/sales', [
                'items' => [
                    ['product_id' => $service->id, 'quantity' => 2, 'unit_price' => 5000],
                ],
                'subtotal' => 10000,
                'discount_amount' => 0,
                'total_amount' => 10000,
                'payment_method' => 'cash',
            ]);

        $response->assertStatus(201);
        $this->assertEquals(0, $service->fresh()->stock_quantity);
        $this->assertEquals(0, StockMovement::where('product_id', $service->id)->count());
    }

    public function test_sale_of_product_still_stock_gated(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'type' => Product::TYPE_PRODUCT,
            'unit_price' => 1000,
            'stock_quantity' => 1,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/sales', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 1000],
                ],
                'subtotal' => 5000,
                'discount_amount' => 0,
                'total_amount' => 5000,
                'payment_method' => 'cash',
            ]);

        $response->assertStatus(422);
        $this->assertEquals(1, $product->fresh()->stock_quantity);
    }

    public function test_mixed_cart_journal_entry_splits_4100_and_4200(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'type' => Product::TYPE_PRODUCT,
            'unit_price' => 3000,
            'cost_price' => 0,
            'stock_quantity' => 10,
        ]);
        $service = Product::factory()->service()->create([
            'business_id' => $this->business->id,
            'unit_price' => 2000,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/sales', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 3000],
                    ['product_id' => $service->id, 'quantity' => 1, 'unit_price' => 2000],
                ],
                'subtotal' => 5000,
                'discount_amount' => 0,
                'total_amount' => 5000,
                'payment_method' => 'cash',
            ]);

        $response->assertStatus(201);
        $saleId = $response->json('id');

        $entry = JournalEntry::query()
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'sale')
            ->where('reference_id', $saleId)
            ->firstOrFail();

        $salesRevenueId = ChartOfAccount::where('business_id', $this->business->id)->where('code', '4100')->value('id');
        $serviceRevenueId = ChartOfAccount::where('business_id', $this->business->id)->where('code', '4200')->value('id');

        $this->assertEquals(3000.0, $this->lineCredit($entry, $salesRevenueId));
        $this->assertEquals(2000.0, $this->lineCredit($entry, $serviceRevenueId));

        $debits = (float) JournalEntryLine::where('entry_id', $entry->id)->sum('debit_amount');
        $credits = (float) JournalEntryLine::where('entry_id', $entry->id)->sum('credit_amount');
        $this->assertEquals($debits, $credits);
    }

    public function test_service_refund_does_not_change_stock(): void
    {
        $service = Product::factory()->service()->create([
            'business_id' => $this->business->id,
            'unit_price' => 4000,
            'stock_quantity' => 0,
        ]);

        $create = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/sales', [
                'items' => [
                    ['product_id' => $service->id, 'quantity' => 1, 'unit_price' => 4000],
                ],
                'subtotal' => 4000,
                'discount_amount' => 0,
                'total_amount' => 4000,
                'payment_method' => 'cash',
            ]);
        $create->assertStatus(201);
        $saleId = $create->json('id');
        $itemId = SaleItem::where('sale_id', $saleId)->value('id');

        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/v1/sales/{$saleId}/refund", [
                'items' => [['id' => $itemId, 'quantity' => 1]],
            ])
            ->assertStatus(200);

        $this->assertEquals(0, $service->fresh()->stock_quantity);
        $this->assertEquals(0, StockMovement::where('product_id', $service->id)->where('type', 'return')->count());
    }

    public function test_product_refund_restores_stock(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'type' => Product::TYPE_PRODUCT,
            'unit_price' => 2500,
            'stock_quantity' => 5,
        ]);

        $create = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/sales', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 2500],
                ],
                'subtotal' => 5000,
                'discount_amount' => 0,
                'total_amount' => 5000,
                'payment_method' => 'cash',
            ]);
        $create->assertStatus(201);
        $this->assertEquals(3, $product->fresh()->stock_quantity);

        $saleId = $create->json('id');
        $itemId = SaleItem::where('sale_id', $saleId)->value('id');

        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/v1/sales/{$saleId}/refund", [
                'items' => [['id' => $itemId, 'quantity' => 1]],
            ])
            ->assertStatus(200);

        $this->assertEquals(4, $product->fresh()->stock_quantity);
        $this->assertEquals(1, StockMovement::where('product_id', $product->id)->where('type', 'return')->count());
    }

    public function test_invoice_send_without_sale_splits_revenue_by_line_type(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'type' => Product::TYPE_PRODUCT,
            'unit_price' => 1000,
            'stock_quantity' => 10,
        ]);
        $service = Product::factory()->service()->create([
            'business_id' => $this->business->id,
            'unit_price' => 1500,
        ]);
        $customer = Customer::factory()->create(['business_id' => $this->business->id]);

        $invoice = Invoice::create([
            'business_id' => $this->business->id,
            'invoice_number' => 'INV-SVC-1',
            'customer_id' => $customer->id,
            'sale_id' => null,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => 'sent',
            'subtotal' => 3500,
            'tax_total' => 0,
            'total_amount' => 3500,
            'amount_paid' => 0,
            'created_by' => $this->admin->id,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'description' => $product->name,
            'quantity' => 1,
            'unit_price' => 1000,
            'subtotal' => 1000,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $service->id,
            'description' => $service->name,
            'quantity' => 1,
            'unit_price' => 1500,
            'subtotal' => 1500,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => null,
            'description' => 'Consulting hours',
            'quantity' => 1,
            'unit_price' => 1000,
            'subtotal' => 1000,
        ]);

        event(new InvoiceSentForAccounting($invoice->fresh(['customer', 'items.product'])));

        $entry = JournalEntry::query()
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'invoice')
            ->where('reference_id', $invoice->id)
            ->firstOrFail();

        $salesRevenueId = ChartOfAccount::where('business_id', $this->business->id)->where('code', '4100')->value('id');
        $serviceRevenueId = ChartOfAccount::where('business_id', $this->business->id)->where('code', '4200')->value('id');

        $this->assertEquals(1000.0, $this->lineCredit($entry, $salesRevenueId));
        $this->assertEquals(2500.0, $this->lineCredit($entry, $serviceRevenueId));

        $debits = (float) JournalEntryLine::where('entry_id', $entry->id)->sum('debit_amount');
        $credits = (float) JournalEntryLine::where('entry_id', $entry->id)->sum('credit_amount');
        $this->assertEquals($debits, $credits);
    }

    public function test_create_service_forces_stock_zero(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/products', [
                'name' => 'Haircut',
                'type' => 'service',
                'unit_price' => 15000,
                'stock_quantity' => 99,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('type', 'service')
            ->assertJsonPath('stock_quantity', 0);
    }

    protected function lineCredit(JournalEntry $entry, int $accountId): float
    {
        return (float) JournalEntryLine::query()
            ->where('entry_id', $entry->id)
            ->where('account_id', $accountId)
            ->sum('credit_amount');
    }
}
