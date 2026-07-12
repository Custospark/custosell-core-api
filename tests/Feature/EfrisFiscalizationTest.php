<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\FiscalizeSaleJob;
use App\Models\Business;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Efris\EfrisServiceInterface;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EfrisFiscalizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Business $business;
    protected string $token;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->token = $this->admin->createToken('admin')->plainTextToken;

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
                'sales.create' => true, 'sales.view' => true,
                'inventory.view' => true, 'inventory.create' => true,
                'customers.view' => true, 'settings.view' => true,
            ],
        ]);
        $this->admin->role_id = $role->id;
        $this->admin->save();

        $this->product = Product::factory()->create([
            'business_id' => $this->business->id,
            'unit_price' => 1000,
            'stock_quantity' => 50,
        ]);
    }

    public function test_status_endpoint_returns_safe_flags_when_disabled(): void
    {
        config(['efris.enabled' => false]);

        $res = $this->withToken($this->token)->getJson('/api/v1/efris/status');

        $res->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonStructure([
                'enabled',
                'configured',
                'country',
                'mode',
                'environment',
                'offline_mode',
                'scope' => ['pos_sales', 'sales_invoices'],
                'misconfigured',
            ]);
        $this->assertArrayNotHasKey('api_password', $res->json());
        $this->assertArrayNotHasKey('tin', $res->json());
    }

    public function test_sale_create_skips_fiscalization_when_disabled(): void
    {
        config(['efris.enabled' => false]);
        Queue::fake();
        Http::fake();

        $res = $this->createSale();

        $res->assertCreated();
        $sale = Sale::query()->first();
        $this->assertNotNull($sale);
        $this->assertSame('none', $sale->fiscal_status ?? 'none');
        Queue::assertNotPushed(FiscalizeSaleJob::class);
        Http::assertNothingSent();
    }

    public function test_sale_marks_failed_when_enabled_but_misconfigured(): void
    {
        config([
            'efris.enabled' => true,
            'efris.country' => 'UG',
            'efris.mode' => 'api',
            'efris.scope.pos_sales' => true,
            'efris.tin' => null,
            'efris.device_no' => null,
            'efris.api_username' => null,
            'efris.api_password' => null,
        ]);

        config(['efris.enabled' => false]);
        $res = $this->createSale();
        $res->assertCreated();
        $sale = Sale::query()->first();

        config([
            'efris.enabled' => true,
            'efris.tin' => null,
            'efris.device_no' => null,
            'efris.api_username' => null,
            'efris.api_password' => null,
        ]);

        /** @var EfrisServiceInterface $efris */
        $efris = $this->app->make(EfrisServiceInterface::class);
        $updated = $efris->fiscalizeSale($sale, forceSync: true);

        $this->assertSame('failed', $updated->fiscal_status);
        $this->assertStringContainsString('credentials', (string) $updated->fiscal_last_error);
        $this->assertNull($updated->fiscal_fdn);
    }

    public function test_sale_fiscalizes_when_enabled_and_ura_succeeds(): void
    {
        config(['efris.enabled' => false]);
        $res = $this->createSale();
        $res->assertCreated();
        $sale = Sale::query()->first();

        config([
            'efris.enabled' => true,
            'efris.country' => 'UG',
            'efris.mode' => 'api',
            'efris.scope.pos_sales' => true,
            'efris.tin' => '1000000000',
            'efris.device_no' => 'DEV001',
            'efris.branch_id' => '01',
            'efris.api_username' => 'user',
            'efris.api_password' => 'pass',
            'efris.base_url' => 'https://efris.test',
        ]);

        Http::fake([
            'efris.test/*' => Http::response([
                'data' => [
                    'basicInformation' => [
                        'invoiceNo' => 'FDN-12345',
                        'antiFakeCode' => 'ABC',
                    ],
                    'summary' => ['qrCode' => 'QRDATA'],
                ],
            ], 200),
        ]);

        /** @var EfrisServiceInterface $efris */
        $efris = $this->app->make(EfrisServiceInterface::class);
        $updated = $efris->fiscalizeSale($sale, forceSync: true);

        $this->assertSame('fiscalized', $updated->fiscal_status);
        $this->assertSame('FDN-12345', $updated->fiscal_fdn);
        $this->assertSame('QRDATA', $updated->fiscal_qr);
    }

    private function createSale()
    {
        return $this->withToken($this->token)->postJson('/api/v1/sales', [
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1000],
            ],
            'subtotal' => 1000,
            'discount_amount' => 0,
            'total_amount' => 1000,
            'payment_method' => 'cash',
            'amount_tendered' => 1000,
            'amount_paid' => 1000,
        ]);
    }
}
