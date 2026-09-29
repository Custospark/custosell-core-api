<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendLowStockDigestEmail;
use App\Jobs\SendOrderPlacedEmail;
use App\Mail\StandardEmail;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

class StorefrontOrderNotificationsTest extends StorefrontTestCase
{
    public function test_placing_order_dispatches_owner_email_job(): void
    {
        Queue::fake();

        $buyer = User::factory()->create(['is_active' => true]);
        $buyerToken = $buyer->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/orders', [
                'customer_name' => 'Sale Shopper',
                'customer_phone' => '+256700000088',
                'items' => [
                    ['product_id' => $this->listed->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        Queue::assertPushed(SendOrderPlacedEmail::class, 1);
    }

    public function test_low_stock_digest_emails_owner_with_items(): void
    {
        Mail::fake();

        Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Almost Gone Flour',
            'is_active' => true,
            'type' => Product::TYPE_PRODUCT,
            'stock_quantity' => 2,
            'low_stock_threshold' => 10,
        ]);

        (new SendLowStockDigestEmail($this->business->id))->handle();

        Mail::assertSent(StandardEmail::class, function (StandardEmail $mail) {
            return str_contains($mail->title, 'Low stock')
                && str_contains($mail->mailBody, 'Almost Gone Flour');
        });
    }

    public function test_low_stock_digest_stays_silent_when_stocked(): void
    {
        Mail::fake();

        $this->listed->update(['stock_quantity' => 100, 'low_stock_threshold' => 5]);
        $this->unlisted->update(['stock_quantity' => 100, 'low_stock_threshold' => 5]);

        (new SendLowStockDigestEmail($this->business->id))->handle();

        Mail::assertNotSent(StandardEmail::class);
    }

    public function test_low_stock_digest_command_queues_per_business(): void
    {
        Queue::fake();

        $this->artisan('inventory:notify-low-stock')->assertSuccessful();

        Queue::assertPushed(SendLowStockDigestEmail::class, 1);
    }

    public function test_digest_skips_business_without_owner_email(): void
    {
        Mail::fake();

        $ownerless = User::factory()->create(['is_active' => true, 'email' => '']);
        $business = Business::factory()->create([
            'owner_id' => $ownerless->id,
            'status' => 'active',
            'currency' => 'UGX',
        ]);
        Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Ghost Stock',
            'is_active' => true,
            'type' => Product::TYPE_PRODUCT,
            'stock_quantity' => 0,
            'low_stock_threshold' => 5,
        ]);

        (new SendLowStockDigestEmail($business->id))->handle();

        Mail::assertNotSent(StandardEmail::class);
    }
}
