<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\AutomationService;
use App\Services\InvoiceService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsAccounting;
use Tests\TestCase;

class InvoiceLinkedSalePaymentSyncTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAccounting;

    protected Business $business;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create(['owner_id' => $this->user->id]);
        $this->user->business_id = $this->business->id;
        $this->user->save();

        Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => [
                'invoices.view' => true,
                'invoices.create' => true,
                'invoices.edit' => true,
                'sales.view' => true,
            ],
        ]);

        $this->seedAccountingForBusiness($this->business);
        Sanctum::actingAs($this->user);
    }

    public function test_invoice_payment_syncs_linked_credit_sale_and_posts_ar_collection(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'cost_price' => 100,
            'stock_quantity' => 10,
        ]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'receipt_number' => 'SALE-CREDIT-1',
            'subtotal' => 500,
            'tax_total' => 0,
            'discount_amount' => 0,
            'total_amount' => 500,
            'amount_paid' => 0,
            'payment_method' => 'cash',
            'payment_status' => 'partially_paid',
            'sale_date' => now(),
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 500,
            'quantity' => 1,
            'unit_price' => 500,
            'unit_cost' => 100,
            'subtotal' => 500,
            'tax_amount' => 0,
            'discount_amount' => 0,
        ]);

        app(AutomationService::class)->handleSaleCreated($sale->load('saleItems'));

        $customer = Customer::factory()->create(['business_id' => $this->business->id]);
        $invoice = Invoice::create([
            'business_id' => $this->business->id,
            'invoice_number' => 'INV-CREDIT-1',
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => 'sent',
            'subtotal' => 500,
            'tax_total' => 0,
            'total_amount' => 500,
            'amount_paid' => 0,
            'created_by' => $this->user->id,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'description' => $product->name,
            'quantity' => 1,
            'unit_price' => 500,
            'subtotal' => 500,
        ]);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/payment", [
            'amount' => 200,
            'payment_method' => 'cash',
        ]);

        $response->assertOk();

        $sale->refresh();
        $invoice->refresh();

        $this->assertEquals(200.0, (float) $sale->amount_paid);
        $this->assertEquals('partially_paid', $sale->payment_status);
        $this->assertEquals(200.0, (float) $invoice->amount_paid);
        $this->assertEquals('partially_paid', $invoice->status);

        $salePayments = Payment::query()
            ->where('payable_type', 'sale')
            ->where('payable_id', $sale->id)
            ->get();
        $this->assertCount(1, $salePayments);
        $this->assertStringContainsString('From invoice', (string) $salePayments->first()->notes);

        $invoicePayments = Payment::query()
            ->where('payable_type', 'invoice')
            ->where('payable_id', $invoice->id)
            ->get();
        $this->assertCount(1, $invoicePayments);

        $collectionEntry = JournalEntry::query()
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'invoice_payment')
            ->where('reference_id', $invoicePayments->first()->id)
            ->first();
        $this->assertNotNull($collectionEntry);

        $mirrorAccounting = JournalEntry::query()
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'sale_payment')
            ->where('reference_id', $salePayments->first()->id)
            ->first();
        $this->assertNull($mirrorAccounting);
    }

    public function test_sale_payment_syncs_linked_invoice_amount_paid(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id, 'stock_quantity' => 5]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'receipt_number' => 'SALE-CREDIT-2',
            'subtotal' => 300,
            'tax_total' => 0,
            'discount_amount' => 0,
            'total_amount' => 300,
            'amount_paid' => 0,
            'payment_method' => 'mobile_money',
            'payment_status' => 'partially_paid',
            'sale_date' => now(),
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 300,
            'quantity' => 1,
            'unit_price' => 300,
            'unit_cost' => 50,
            'subtotal' => 300,
            'tax_amount' => 0,
            'discount_amount' => 0,
        ]);

        app(AutomationService::class)->handleSaleCreated($sale->load('saleItems'));

        $invoice = Invoice::create([
            'business_id' => $this->business->id,
            'invoice_number' => 'INV-CREDIT-2',
            'customer_id' => null,
            'sale_id' => $sale->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'sent',
            'subtotal' => 300,
            'tax_total' => 0,
            'total_amount' => 300,
            'amount_paid' => 0,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/sales/{$sale->id}/payment", [
            'amount' => 150,
            'payment_method' => 'mobile_money',
        ]);

        $response->assertOk();

        $sale->refresh();
        $invoice->refresh();

        $this->assertEquals(150.0, (float) $sale->amount_paid);
        $this->assertEquals(150.0, (float) $invoice->amount_paid);
        $this->assertEquals('partially_paid', $invoice->status);
    }

    public function test_invoice_payment_attributes_active_shift_not_sale_shift(): void
    {
        $oldShift = Shift::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'clock_in' => now()->subDay(),
            'clock_out' => now()->subDay()->addHours(8),
            'status' => 'completed',
            'total_sales' => 0,
            'total_cash' => 0,
            'total_mobile_money' => 0,
            'total_card' => 0,
        ]);

        $activeShift = Shift::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'clock_in' => now()->subHour(),
            'clock_out' => null,
            'status' => 'active',
            'total_sales' => 0,
            'total_cash' => 0,
            'total_mobile_money' => 0,
            'total_card' => 0,
        ]);

        $product = Product::factory()->create(['business_id' => $this->business->id, 'stock_quantity' => 5]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'shift_id' => $oldShift->id,
            'receipt_number' => 'SALE-OLD-SHIFT',
            'subtotal' => 400,
            'tax_total' => 0,
            'discount_amount' => 0,
            'total_amount' => 400,
            'amount_paid' => 0,
            'payment_method' => 'cash',
            'payment_status' => 'partially_paid',
            'sale_date' => now()->subDay(),
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 400,
            'quantity' => 1,
            'unit_price' => 400,
            'unit_cost' => 50,
            'subtotal' => 400,
            'tax_amount' => 0,
            'discount_amount' => 0,
        ]);

        $invoice = Invoice::create([
            'business_id' => $this->business->id,
            'invoice_number' => 'INV-SHIFT-ATTR',
            'customer_id' => null,
            'sale_id' => $sale->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'sent',
            'subtotal' => 400,
            'tax_total' => 0,
            'total_amount' => 400,
            'amount_paid' => 0,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/payment", [
            'amount' => 100,
            'payment_method' => 'cash',
        ]);

        $response->assertOk();

        $invoicePayment = Payment::query()
            ->where('payable_type', 'invoice')
            ->where('payable_id', $invoice->id)
            ->first();

        $this->assertNotNull($invoicePayment);
        $this->assertEquals($activeShift->id, $invoicePayment->shift_id);

        $shiftPayments = $this->getJson("/api/v1/shifts/{$activeShift->id}/payments");
        $shiftPayments->assertOk();
        $shiftPayments->assertJsonPath('data.0.id', $invoicePayment->id);
        $this->assertEquals(100.0, (float) $shiftPayments->json('data.0.amount'));

        $oldShiftPayments = $this->getJson("/api/v1/shifts/{$oldShift->id}/payments");
        $oldShiftPayments->assertOk();
        $this->assertCount(0, $oldShiftPayments->json('data'));
    }
}
