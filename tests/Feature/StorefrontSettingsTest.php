<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;

class StorefrontSettingsTest extends StorefrontTestCase
{
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

    public function test_enable_storefront_requires_slug(): void
    {
        \Illuminate\Support\Facades\DB::table('businesses')->where('id', $this->business->id)->update([
            'slug' => '',
            'storefront_enabled' => false,
        ]);

        $this->withToken($this->token)
            ->patchJson('/api/v1/businesses/storefront-profile', [
                'storefront_enabled' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_enable_storefront_with_new_slug(): void
    {
        $this->business->update([
            'storefront_enabled' => false,
            'slug' => 'old-shop-name',
        ]);

        $this->withToken($this->token)
            ->patchJson('/api/v1/businesses/storefront-profile', [
                'storefront_enabled' => true,
                'slug' => 'fresh-cafe-name',
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'fresh-cafe-name')
            ->assertJsonPath('data.storefront_enabled', true);

        $this->getJson('/api/v1/storefront/fresh-cafe-name')
            ->assertOk()
            ->assertJsonPath('slug', 'fresh-cafe-name');
    }
}
