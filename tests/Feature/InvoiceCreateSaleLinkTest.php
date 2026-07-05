<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
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

class InvoiceCreateSaleLinkTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAccounting;

    protected Business $business;

    protected User $user;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->token = $this->user->createToken('admin')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->user->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->user->business_id = $this->business->id;
        $this->user->save();

        Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => [
                'invoices.create' => true,
                'invoices.view' => true,
                'sales.view' => true,
            ],
        ]);

        $this->seedAccountingForBusiness($this->business);
    }

    public function test_create_invoice_persists_sale_id(): void
    {
        $customer = Customer::factory()->create(['business_id' => $this->business->id]);
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'cost_price' => 100,
            'stock_quantity' => 10,
        ]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'customer_id' => $customer->id,
            'receipt_number' => 'SALE-INV-1',
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

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/invoices', [
                'customer_id' => $customer->id,
                'sale_id' => $sale->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
                'items' => [[
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'subtotal' => 500,
                ]],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('sale_id', $sale->id)
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('amount_paid', '500.00');
    }

    public function test_create_invoice_from_partially_paid_sale_inherits_amount_paid(): void
    {
        $customer = Customer::factory()->create(['business_id' => $this->business->id]);
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'cost_price' => 100,
            'stock_quantity' => 10,
        ]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'customer_id' => $customer->id,
            'receipt_number' => 'SALE-INV-PARTIAL',
            'subtotal' => 1000,
            'tax_total' => 0,
            'discount_amount' => 0,
            'total_amount' => 1000,
            'amount_paid' => 400,
            'payment_method' => 'cash',
            'payment_status' => 'partially_paid',
            'sale_date' => now(),
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 1000,
            'quantity' => 1,
            'unit_price' => 1000,
            'unit_cost' => 100,
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
        ]);

        app(AutomationService::class)->handleSaleCreated($sale->load('saleItems'));

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/invoices', [
                'customer_id' => $customer->id,
                'sale_id' => $sale->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
                'items' => [[
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'subtotal' => 1000,
                ]],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('amount_paid', '400.00')
            ->assertJsonCount(1, 'payments');
    }

    public function test_send_linked_fully_paid_invoice_sets_status_paid(): void
    {
        $customer = Customer::factory()->create(['business_id' => $this->business->id]);
        $product = Product::factory()->create(['business_id' => $this->business->id, 'stock_quantity' => 5]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'customer_id' => $customer->id,
            'receipt_number' => 'SALE-INV-PAID',
            'subtotal' => 300,
            'tax_total' => 0,
            'discount_amount' => 0,
            'total_amount' => 300,
            'amount_paid' => 300,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
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

        $create = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/invoices', [
                'customer_id' => $customer->id,
                'sale_id' => $sale->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(7)->toDateString(),
                'items' => [[
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'quantity' => 1,
                    'unit_price' => 300,
                ]],
            ]);

        $create->assertStatus(201);
        $invoiceId = $create->json('id');

        $send = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/invoices/{$invoiceId}/send");

        $send->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('amount_paid', '300.00');
    }
}
