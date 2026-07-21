<?php

namespace Tests\Feature;

use App\Models\{Business, Order, Plan, Product, Role, Sale, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected string $token;
    protected Business $business;
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
                'sales.create' => true, 'sales.view' => true, 'sales.refund' => true,
                'inventory.view' => true, 'inventory.create' => true,
            ],
        ]);
        $this->admin->role_id = $role->id;
        $this->admin->modules = ['sales', 'inventory', 'customers', 'dashboard', 'settings'];
        $this->admin->save();

        $this->product = Product::factory()->create([
            'business_id' => $this->business->id,
            'stock_quantity' => 50,
            'unit_price' => 1000,
        ]);
        $this->setUpSubscription();
    }

    protected function authJson(string $method, string $uri, array $data = [])
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}")
            ->json($method, $uri, $data);
    }

    public function test_create_open_order(): void
    {
        $response = $this->authJson('POST', '/api/v1/orders', [
            'customer_name' => 'Table 4',
            'notes' => 'No onions',
            'discount_amount' => 0,
            'tax_total' => 0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => 1000,
                    'subtotal' => 2000,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('customer_name', 'Table 4');

        $this->assertDatabaseHas('orders', [
            'business_id' => $this->business->id,
            'status' => 'open',
            'customer_name' => 'Table 4',
        ]);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_sale_completes_open_order(): void
    {
        $order = $this->authJson('POST', '/api/v1/orders', [
            'customer_name' => 'Guest',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000],
            ],
        ])->json();

        $saleResponse = $this->authJson('POST', '/api/v1/sales', [
            'order_id' => $order['id'],
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1000],
            ],
            'subtotal' => 1000,
            'tax_total' => 0,
            'discount_amount' => 0,
            'total_amount' => 1000,
            'amount_paid' => 500,
            'payment_method' => 'cash',
            'sale_date' => now()->toDateTimeString(),
        ]);

        $saleResponse->assertStatus(201)
            ->assertJsonPath('order_id', $order['id'])
            ->assertJsonPath('payment_status', 'partially_paid');

        $this->assertDatabaseHas('orders', [
            'id' => $order['id'],
            'status' => 'completed',
            'sale_id' => $saleResponse->json('id') ?? $saleResponse->json('data.id'),
        ]);
    }

    public function test_cannot_complete_non_open_order_twice(): void
    {
        $order = $this->authJson('POST', '/api/v1/orders', [
            'customer_name' => 'Guest',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000],
            ],
        ])->json();

        $this->authJson('POST', '/api/v1/sales', [
            'order_id' => $order['id'],
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1000],
            ],
            'subtotal' => 1000,
            'total_amount' => 1000,
            'amount_paid' => 1000,
            'payment_method' => 'cash',
            'sale_date' => now()->toDateTimeString(),
        ])->assertStatus(201);

        $this->authJson('POST', '/api/v1/sales', [
            'order_id' => $order['id'],
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1000],
            ],
            'subtotal' => 1000,
            'total_amount' => 1000,
            'amount_paid' => 1000,
            'payment_method' => 'cash',
            'sale_date' => now()->toDateTimeString(),
        ])->assertStatus(422);
    }

    public function test_invoice_from_sale_marks_order_invoiced(): void
    {
        $order = $this->authJson('POST', '/api/v1/orders', [
            'customer_name' => 'Guest',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000],
            ],
        ])->json();

        $sale = $this->authJson('POST', '/api/v1/sales', [
            'order_id' => $order['id'],
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1000],
            ],
            'subtotal' => 1000,
            'total_amount' => 1000,
            'amount_paid' => 1000,
            'payment_method' => 'cash',
            'sale_date' => now()->toDateTimeString(),
        ])->json();

        $saleId = $sale['id'] ?? $sale['data']['id'];

        $invoice = $this->authJson('POST', '/api/v1/invoices', [
            'sale_id' => $saleId,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'tax_total' => 0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'description' => $this->product->name,
                    'quantity' => 1,
                    'unit_price' => 1000,
                ],
            ],
        ]);

        $invoice->assertStatus(201);

        $this->assertDatabaseHas('orders', [
            'id' => $order['id'],
            'status' => 'invoiced',
            'sale_id' => $saleId,
        ]);
    }

    public function test_cancel_open_order(): void
    {
        $order = $this->authJson('POST', '/api/v1/orders', [
            'customer_name' => 'Guest',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000],
            ],
        ])->json();

        $this->authJson('POST', "/api/v1/orders/{$order['id']}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('status', 'cancelled');
    }

    public function test_list_filters_open_orders(): void
    {
        $this->authJson('POST', '/api/v1/orders', [
            'customer_name' => 'Open one',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000],
            ],
        ]);

        $this->authJson('GET', '/api/v1/orders?status=open')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }
}
