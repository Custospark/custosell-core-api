<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Business $business;
    protected Product $listed;
    protected Product $unlisted;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->token = $this->owner->createToken('t')->plainTextToken;
        $this->business = Business::factory()->create([
            'owner_id' => $this->owner->id,
            'slug' => 'devine-mercy-restaurant',
            'status' => 'active',
            'storefront_enabled' => true,
            'currency' => 'UGX',
        ]);
        $this->owner->business_id = $this->business->id;
        $this->owner->save();

        $role = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => [
                'sales.view' => true, 'sales.create' => true,
                'inventory.view' => true, 'inventory.create' => true,
                'settings.view' => true, 'settings.edit' => true,
            ],
        ]);
        $this->owner->role_id = $role->id;
        $this->owner->save();

        $category = Category::create([
            'business_id' => $this->business->id,
            'name' => 'Meals',
            'sort_order' => 1,
        ]);

        $this->listed = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Chicken Plate',
            'unit_price' => 15000,
            'is_active' => true,
            'type' => Product::TYPE_PRODUCT,
            'stock_quantity' => 25,
            'listed_for_storefront' => true,
            'storefront_listed_at' => now(),
        ]);

        $this->unlisted = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Hidden Item',
            'unit_price' => 5000,
            'is_active' => true,
            'listed_for_storefront' => false,
        ]);
    }

    public function test_shop_404_when_storefront_disabled(): void
    {
        $this->business->update(['storefront_enabled' => false]);

        $this->getJson('/api/v1/storefront/devine-mercy-restaurant')
            ->assertNotFound();
    }

    public function test_discover_lists_only_storefront_products(): void
    {
        $res = $this->getJson('/api/v1/storefront/discover');
        $res->assertOk();
        $names = collect($res->json('data'))->pluck('name')->all();
        $this->assertContains('Chicken Plate', $names);
        $this->assertNotContains('Hidden Item', $names);
    }

    public function test_shops_lists_enabled_shop_even_without_listed_products(): void
    {
        $emptyEnabled = Business::factory()->create([
            'name' => 'Empty Enabled Cafe',
            'slug' => 'empty-enabled-cafe',
            'status' => 'active',
            'storefront_enabled' => true,
        ]);

        $disabled = Business::factory()->create([
            'name' => 'Disabled Shop',
            'slug' => 'disabled-shop',
            'status' => 'active',
            'storefront_enabled' => false,
        ]);

        $res = $this->getJson('/api/v1/storefront/shops');
        $res->assertOk();

        $slugs = collect($res->json('data'))->pluck('slug')->all();
        $this->assertContains($this->business->slug, $slugs);
        $this->assertContains($emptyEnabled->slug, $slugs);
        $this->assertNotContains($disabled->slug, $slugs);
        $this->assertArrayHasKey('meta', $res->json());
    }

    public function test_guest_cannot_place_storefront_order(): void
    {
        $this->postJson('/api/v1/storefront/devine-mercy-restaurant/orders', [
            'customer_name' => 'Amina',
            'customer_phone' => '+256700000001',
            'notes' => 'Extra sauce',
            'items' => [
                ['product_id' => $this->listed->id, 'quantity' => 2],
            ],
        ])->assertUnauthorized();
    }

    public function test_authenticated_buyer_sees_own_storefront_orders(): void
    {
        $buyer = User::factory()->create(['is_active' => true]);
        $buyerToken = $buyer->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/orders', [
                'customer_name' => 'Buyer User',
                'customer_phone' => '+256700000099',
                'items' => [
                    ['product_id' => $this->listed->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        $order = Order::query()->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame($buyer->id, $order->storefront_buyer_user_id);

        $res = $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->getJson('/api/v1/storefront/my-orders');

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($this->business->slug, $res->json('data.0.shop_slug'));
        $this->assertSame($this->business->name, $res->json('data.0.shop_name'));
        $this->assertSame('+256700000099', $res->json('data.0.customer_phone'));
        $this->assertIsArray($res->json('data.0.items'));
        $this->assertCount(1, $res->json('data.0.items'));
        $this->assertSame($this->listed->name, $res->json('data.0.items.0.product_name'));
        $this->assertSame(1, (int) $res->json('data.0.items.0.quantity'));

        $buyer->refresh();
        $this->assertSame('+256700000099', $buyer->phone);
    }

    public function test_rejects_unlisted_product_on_order(): void
    {
        $buyer = User::factory()->create(['is_active' => true]);
        $buyerToken = $buyer->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/orders', [
                'customer_name' => 'Amina',
                'customer_phone' => '+256700000001',
                'items' => [
                    ['product_id' => $this->unlisted->id, 'quantity' => 1],
                ],
            ])->assertStatus(422);
    }

    public function test_shops_include_contact_fields(): void
    {
        $this->business->update([
            'description' => 'Fresh meals daily',
            'city' => 'Kampala',
            'country' => 'Uganda',
            'address' => 'Plot 1 Kampala Road',
            'business_phone' => '+256700111222',
            'business_email' => 'hello@devine.test',
        ]);

        $res = $this->getJson('/api/v1/storefront/shops');
        $res->assertOk();
        $shop = collect($res->json('data'))->firstWhere('slug', $this->business->slug);
        $this->assertNotNull($shop);
        $this->assertSame('Fresh meals daily', $shop['description']);
        $this->assertSame('Plot 1 Kampala Road', $shop['address']);
        $this->assertSame('+256700111222', $shop['business_phone']);
        $this->assertSame('hello@devine.test', $shop['business_email']);
    }

    public function test_authenticated_buyer_can_rate_listed_product(): void
    {
        $buyer = User::factory()->create(['is_active' => true]);
        $buyerToken = $buyer->createToken('t')->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/products/'.$this->listed->id.'/ratings', [
                'rating' => 5,
            ]);

        $res->assertOk()
            ->assertJsonPath('data.rating_avg', 5)
            ->assertJsonPath('data.rating_count', 1)
            ->assertJsonPath('data.my_rating', 5);

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/products/'.$this->listed->id.'/ratings', [
                'rating' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('data.rating_avg', 4)
            ->assertJsonPath('data.rating_count', 1)
            ->assertJsonPath('data.my_rating', 4);
    }

    public function test_guest_cannot_rate_product(): void
    {
        $this->postJson('/api/v1/storefront/devine-mercy-restaurant/products/'.$this->listed->id.'/ratings', [
            'rating' => 5,
        ])->assertUnauthorized();
    }

    public function test_authenticated_buyer_can_rate_shop(): void
    {
        $buyer = User::factory()->create(['is_active' => true]);
        $buyerToken = $buyer->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/ratings', [
                'rating' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('data.rating_avg', 5)
            ->assertJsonPath('data.rating_count', 1)
            ->assertJsonPath('data.my_rating', 5);
    }

    public function test_slug_available_endpoint(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/v1/businesses/slug-available?slug=new-cafe')
            ->assertOk()
            ->assertJsonPath('available', true);

        $this->withToken($this->token)
            ->getJson('/api/v1/businesses/slug-available?slug=devine-mercy-restaurant')
            ->assertOk()
            ->assertJsonPath('available', true); // same business ignored
    }

    public function test_storefront_buyer_register_has_no_business(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name' => 'Discover Shopper',
            'email' => 'shopper@example.com',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
            'phone' => '+256700111222',
            'account_type' => 'storefront_buyer',
        ]);

        $res->assertCreated();
        $this->assertNull($res->json('user.data.business_id') ?? $res->json('user.business_id'));
        $this->assertSame([], $res->json('user.data.modules') ?? $res->json('user.modules') ?? []);

        $user = User::query()->where('email', 'shopper@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->business_id);
        $this->assertSame([], $user->modules ?? []);
    }

    public function test_storefront_order_attaches_buyer_as_customer(): void
    {
        $buyer = User::factory()->create([
            'is_active' => true,
            'business_id' => null,
            'email' => 'buyer-customer@example.com',
            'phone' => '+256700000088',
        ]);
        $buyerToken = $buyer->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/orders', [
                'customer_name' => 'Buyer Customer',
                'customer_phone' => '+256700000088',
                'items' => [
                    ['product_id' => $this->listed->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        $order = Order::query()->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertNotNull($order->customer_id);
        $this->assertSame($buyer->id, $order->storefront_buyer_user_id);

        $customer = \App\Models\Customer::query()->find($order->customer_id);
        $this->assertNotNull($customer);
        $this->assertSame($this->business->id, $customer->business_id);
        $this->assertSame($buyer->id, $customer->user_id);

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/orders', [
                'customer_name' => 'Buyer Customer',
                'customer_phone' => '+256700000088',
                'items' => [
                    ['product_id' => $this->listed->id, 'quantity' => 2],
                ],
            ])
            ->assertCreated();

        $second = Order::query()->latest('id')->first();
        $this->assertSame($customer->id, $second->customer_id);
        $this->assertSame(1, \App\Models\Customer::query()
            ->where('business_id', $this->business->id)
            ->where('user_id', $buyer->id)
            ->count());

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->getJson('/api/v1/storefront/my-orders/'.$order->id.'/sale')
            ->assertNotFound();
    }

    public function test_buyer_sale_and_invoice_letterhead_use_shop_business_name(): void
    {
        $buyer = User::factory()->create([
            'is_active' => true,
            'business_id' => null,
            'email' => 'buyer-docs@example.com',
            'phone' => '+256700000077',
        ]);
        $buyerToken = $buyer->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/orders', [
                'customer_name' => 'Doc Buyer',
                'customer_phone' => '+256700000077',
                'items' => [
                    ['product_id' => $this->listed->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        $order = Order::query()->latest('id')->first();
        $this->assertNotNull($order);

        $sale = \App\Models\Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'receipt_number' => 'SF-RCPT-1',
            'subtotal' => 15000,
            'tax_total' => 0,
            'discount_amount' => 0,
            'total_amount' => 15000,
            'amount_paid' => 15000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'sale_date' => now(),
        ]);

        \App\Models\SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->listed->id,
            'product_name' => $this->listed->name,
            'product_price' => 15000,
            'quantity' => 1,
            'unit_price' => 15000,
            'unit_cost' => 0,
            'subtotal' => 15000,
            'tax_amount' => 0,
            'discount_amount' => 0,
        ]);

        $order->update(['status' => Order::STATUS_COMPLETED]);

        $saleRes = $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->getJson('/api/v1/storefront/my-orders/'.$order->id.'/sale')
            ->assertOk();

        $saleBusinessName = $saleRes->json('data.business.name') ?? $saleRes->json('business.name');
        $this->assertSame($this->business->name, $saleBusinessName);
        $this->assertNotSame('Custosell', $saleBusinessName);

        $invoice = \App\Models\Invoice::create([
            'business_id' => $this->business->id,
            'invoice_number' => 'SF-INV-1',
            'customer_id' => $order->customer_id,
            'sale_id' => $sale->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'sent',
            'subtotal' => 15000,
            'tax_total' => 0,
            'total_amount' => 15000,
            'amount_paid' => 0,
            'created_by' => $this->owner->id,
        ]);

        \App\Models\InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $this->listed->id,
            'description' => $this->listed->name,
            'quantity' => 1,
            'unit_price' => 15000,
            'subtotal' => 15000,
        ]);

        $invRes = $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->getJson('/api/v1/storefront/my-orders/'.$order->id.'/invoice')
            ->assertOk();

        $this->assertSame('received', $invRes->json('data.direction') ?? $invRes->json('direction'));
        $sellerName = $invRes->json('data.seller_business.name') ?? $invRes->json('seller_business.name');
        $partyName = $invRes->json('data.party_name') ?? $invRes->json('party_name');
        $this->assertSame($this->business->name, $sellerName);
        $this->assertSame($this->business->name, $partyName);
        $this->assertNotSame('Custosell', $sellerName);
        $this->assertNotSame('Doc Buyer', $partyName);

        $pdfRes = $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->get('/api/v1/storefront/my-orders/'.$order->id.'/invoice/pdf');
        $pdfRes->assertOk();
        $this->assertStringContainsString('pdf', strtolower((string) $pdfRes->headers->get('content-type')));

        $stranger = User::factory()->create([
            'is_active' => true,
            'business_id' => null,
            'email' => 'stranger-docs@example.com',
        ]);
        $this->actingAs($stranger, 'sanctum')
            ->get('/api/v1/storefront/my-orders/'.$order->id.'/invoice/pdf')
            ->assertNotFound();
        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/v1/storefront/my-orders/'.$order->id.'/invoice')
            ->assertNotFound();
    }

    public function test_discover_products_include_stock_fields(): void
    {
        $res = $this->getJson('/api/v1/storefront/discover');
        $res->assertOk();

        $item = collect($res->json('data'))->firstWhere('name', 'Chicken Plate');
        $this->assertNotNull($item);
        $this->assertSame(25, (int) $item['stock_quantity']);
        $this->assertTrue($item['in_stock']);
        $this->assertSame('in_stock', $item['availability']);
    }

    public function test_place_order_fails_when_out_of_stock(): void
    {
        $this->listed->update(['stock_quantity' => 0]);

        $buyer = User::factory()->create(['is_active' => true]);
        $buyerToken = $buyer->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/orders', [
                'customer_name' => 'Amina',
                'customer_phone' => '+256700000001',
                'items' => [
                    ['product_id' => $this->listed->id, 'quantity' => 1],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity']);
    }

    public function test_buyer_can_cancel_open_order(): void
    {
        $buyer = User::factory()->create(['is_active' => true]);
        $buyerToken = $buyer->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/orders', [
                'customer_name' => 'Buyer User',
                'customer_phone' => '+256700000099',
                'delivery_address' => 'Plot 5 Jinja Road',
                'delivery_city' => 'Kampala',
                'items' => [
                    ['product_id' => $this->listed->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        $order = Order::query()->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame('Plot 5 Jinja Road', $order->delivery_address);
        $this->assertSame('Kampala', $order->delivery_city);

        $res = $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/my-orders/'.$order->id.'/cancel');

        $res->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_CANCELLED)
            ->assertJsonPath('data.delivery_address', 'Plot 5 Jinja Road')
            ->assertJsonPath('data.delivery_city', 'Kampala');

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_buyer_cannot_cancel_completed_order(): void
    {
        $buyer = User::factory()->create(['is_active' => true]);
        $buyerToken = $buyer->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/orders', [
                'customer_name' => 'Buyer User',
                'customer_phone' => '+256700000099',
                'items' => [
                    ['product_id' => $this->listed->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        $order = Order::query()->latest('id')->first();
        $this->assertNotNull($order);
        $order->update(['status' => Order::STATUS_COMPLETED]);

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/my-orders/'.$order->id.'/cancel')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_buyer_can_delete_cancelled_order(): void
    {
        $buyer = User::factory()->create(['is_active' => true]);
        $buyerToken = $buyer->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/orders', [
                'customer_name' => 'Buyer User',
                'customer_phone' => '+256700000099',
                'items' => [
                    ['product_id' => $this->listed->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        $order = Order::query()->latest('id')->first();
        $this->assertNotNull($order);

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/my-orders/'.$order->id.'/cancel')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->deleteJson('/api/v1/storefront/my-orders/'.$order->id)
            ->assertOk();

        $this->assertSoftDeleted('orders', ['id' => $order->id]);

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->getJson('/api/v1/storefront/my-orders')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
