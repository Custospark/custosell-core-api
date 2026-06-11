<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Role;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\TaxEngine;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $admin;

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
                'expenses.view' => true, 'expenses.create' => true,
                'reports.view' => true, 'settings.view' => true, 'settings.edit' => true,
            ],
        ]);
        $this->admin->role_id = $adminRole->id;
        $this->admin->modules = ['dashboard', 'sales', 'inventory', 'expenses', 'settings'];
        $this->admin->save();

        $this->business->update([
            'tax_regime' => TaxEngine::REGIME_VAT_REGISTERED,
            'default_vat_rate' => 18,
            'prices_include_tax' => true,
            'tax_id' => '1000123456',
        ]);
    }

    public function test_sale_computes_vat_server_side(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'unit_price' => 118000,
            'tax_percentage' => 18,
            'tax_class' => TaxEngine::CLASS_STANDARD,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/sales', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 118000],
                ],
                'subtotal' => 99999,
                'tax_total' => 1,
                'discount_amount' => 0,
                'total_amount' => 99999,
                'payment_method' => 'cash',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('tax_total', '18000.00')
            ->assertJsonPath('subtotal', '100000.00')
            ->assertJsonPath('total_amount', '118000.00');

        $this->assertDatabaseHas('sale_items', [
            'product_id' => $product->id,
            'tax_amount' => 18000,
        ]);
    }

    public function test_refund_reverses_proportional_vat(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'unit_price' => 118000,
            'tax_percentage' => 18,
            'stock_quantity' => 10,
        ]);

        $create = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/sales', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 118000],
                ],
                'subtotal' => 200000,
                'discount_amount' => 0,
                'total_amount' => 236000,
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

        $item = SaleItem::findOrFail($itemId);
        $this->assertEquals(18000.0, (float) $item->tax_refunded_amount);
    }

    public function test_vat_summary_report_json(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'unit_price' => 118000,
            'tax_percentage' => 18,
        ]);

        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/sales', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 118000],
                ],
                'subtotal' => 100000,
                'discount_amount' => 0,
                'total_amount' => 118000,
                'payment_method' => 'cash',
            ])
            ->assertStatus(201);

        $category = ExpenseCategory::query()->whereNull('business_id')->where('slug', 'utilities')->firstOrFail();

        Expense::create([
            'business_id' => $this->business->id,
            'expense_category_id' => $category->id,
            'recorded_by' => $this->admin->id,
            'amount' => 118000,
            'description' => 'Power bill',
            'supplier_tin' => '1000999888',
            'supplier_invoice_no' => 'INV-001',
            'vat_amount' => 18000,
            'vat_claimable' => true,
            'expense_date' => now(),
        ]);

        $from = now()->subDay()->toDateString();
        $to = now()->addDay()->toDateString();

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/vat-summary?date_from={$from}&date_to={$to}&format=json");

        $response->assertStatus(200)
            ->assertJsonPath('data.net_output_vat', 18000)
            ->assertJsonPath('data.input_vat', 18000)
            ->assertJsonPath('data.vat_payable', 0);
    }

    public function test_dashboard_summary_includes_today_vat(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'unit_price' => 118000,
            'tax_percentage' => 18,
        ]);

        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/sales', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 118000],
                ],
                'subtotal' => 100000,
                'discount_amount' => 0,
                'total_amount' => 118000,
                'payment_method' => 'cash',
            ])
            ->assertStatus(201);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/dashboard/summary');

        $response->assertStatus(200)
            ->assertJsonPath('today_vat.net_output_vat', 18000)
            ->assertJsonPath('today_vat.transaction_count', 1);
    }

    public function test_business_mine_returns_tax_settings(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/businesses/mine');

        $response->assertStatus(200)
            ->assertJsonPath('data.tax_regime', TaxEngine::REGIME_VAT_REGISTERED)
            ->assertJsonPath('data.default_vat_rate', '18.00')
            ->assertJsonPath('data.prices_include_tax', true)
            ->assertJsonPath('data.tax_id', '1000123456');
    }

    public function test_auth_me_includes_business_tax_settings(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonPath('data.business.tax_regime', TaxEngine::REGIME_VAT_REGISTERED)
            ->assertJsonPath('data.business.default_vat_rate', '18.00')
            ->assertJsonPath('data.business.prices_include_tax', true);
    }

    public function test_business_update_persists_tax_settings(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->putJson("/api/v1/businesses/{$this->business->id}", [
                'name' => $this->business->name,
                'tax_regime' => TaxEngine::REGIME_VAT_REGISTERED,
                'jurisdiction' => 'KE',
                'default_vat_rate' => 16,
                'prices_include_tax' => false,
                'tax_id' => 'A12345678',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.jurisdiction', 'KE')
            ->assertJsonPath('data.default_vat_rate', '16.00')
            ->assertJsonPath('data.prices_include_tax', false)
            ->assertJsonPath('data.tax_id', 'A12345678');

        $this->business->refresh();
        $this->assertSame(TaxEngine::REGIME_VAT_REGISTERED, $this->business->tax_regime);
        $this->assertSame('KE', $this->business->jurisdiction);
        $this->assertEquals(16.0, (float) $this->business->default_vat_rate);
        $this->assertFalse($this->business->prices_include_tax);
    }

    public function test_sale_response_includes_tax_fields_for_ui(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'unit_price' => 118000,
            'tax_percentage' => 18,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/sales', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 118000],
                ],
                'subtotal' => 100000,
                'discount_amount' => 0,
                'total_amount' => 118000,
                'payment_method' => 'cash',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'tax_total',
                'subtotal',
                'total_amount',
                'sale_items' => [
                    ['tax_amount', 'subtotal', 'unit_price', 'quantity'],
                ],
            ])
            ->assertJsonPath('tax_total', '18000.00');
    }
}
