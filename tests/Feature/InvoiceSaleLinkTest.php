<?php

namespace Tests\Feature;

use App\Events\InvoiceSentForAccounting;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\AutomationService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsAccounting;
use Tests\TestCase;

class InvoiceSaleLinkTest extends TestCase
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
            'permissions' => ['accounting.view' => true],
        ]);

        $this->seedAccountingForBusiness($this->business);
    }

    public function test_invoice_linked_to_accounted_sale_skips_revenue_on_send(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'cost_price' => 100,
            'stock_quantity' => 10,
        ]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'receipt_number' => 'SALE-LINK-1',
            'subtotal' => 500,
            'tax_total' => 0,
            'discount_amount' => 0,
            'total_amount' => 500,
            'amount_paid' => 500,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
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
            'invoice_number' => 'INV-LINK-1',
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => 'draft',
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

        event(new InvoiceSentForAccounting($invoice->fresh(['customer'])));

        $this->assertNull(
            JournalEntry::query()
                ->where('business_id', $this->business->id)
                ->where('reference_type', 'invoice')
                ->where('reference_id', $invoice->id)
                ->first()
        );
    }
}
