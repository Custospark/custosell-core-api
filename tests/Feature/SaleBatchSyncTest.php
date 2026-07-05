<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsAccounting;
use Tests\TestCase;

class SaleBatchSyncTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAccounting;

    protected User $admin;

    protected Business $business;

    protected string $adminToken;

    protected Shift $shift;

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

        Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => ['sales.create' => true, 'sales.view' => true],
        ]);

        $this->seedAccountingForBusiness($this->business);

        $this->shift = Shift::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'clock_in' => now(),
            'status' => 'active',
        ]);
    }

    public function test_batch_sync_preserves_partial_amount_paid(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'stock_quantity' => 50,
            'unit_price' => 1000,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/sales/batch', [
                'sales' => [[
                    'subtotal' => 1000,
                    'tax_total' => 0,
                    'discount_amount' => 0,
                    'total_amount' => 1000,
                    'amount_paid' => 400,
                    'amount_tendered' => 400,
                    'payment_method' => 'cash',
                    'shift_id' => $this->shift->id,
                    'items' => [[
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 1000,
                    ]],
                ]],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('synced', 1)
            ->assertJsonPath('failed', 0);

        $saleId = $response->json('sales.0.id');
        $sale = Sale::findOrFail($saleId);

        $this->assertEquals(400.0, (float) $sale->amount_paid);
        $this->assertEquals('partially_paid', $sale->payment_status);
        $this->assertCount(1, $sale->payments);
        $this->assertEquals(400.0, (float) $sale->payments->first()->amount);
    }
}
