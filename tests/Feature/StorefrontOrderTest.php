<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;

class StorefrontOrderTest extends StorefrontTestCase
{
    public function test_storefront_catalog_and_order_use_product_discount(): void
    {
        $this->listed->update(['discount_percent' => 20]);

        $discover = $this->getJson('/api/v1/storefront/discover');
        $discover->assertOk();
        $row = collect($discover->json('data'))->firstWhere('id', $this->listed->id);
        $this->assertNotNull($row);
        $this->assertEquals(15000, (float) $row['unit_price']);
        $this->assertEquals(20, (float) $row['discount_percent']);
        $this->assertEquals(12000, (float) $row['sale_price']);
        $this->assertEquals(15000, (float) $row['compare_at_price']);

        $buyer = User::factory()->create(['is_active' => true]);
        $buyerToken = $buyer->createToken('t')->plainTextToken;

        $place = $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/orders', [
                'customer_name' => 'Sale Shopper',
                'customer_phone' => '+256700000088',
                'items' => [
                    ['product_id' => $this->listed->id, 'quantity' => 2],
                ],
            ]);

        $place->assertCreated();
        $this->assertEquals(24000.0, (float) $place->json('total_amount'));

        $order = Order::query()->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertEquals(24000.0, (float) $order->total_amount);
        $line = $order->items()->first();
        $this->assertNotNull($line);
        $this->assertEquals(12000.0, (float) $line->unit_price);
        $this->assertEquals(24000.0, (float) $line->subtotal);
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
