<?php

namespace Tests\Unit;

use App\Models\Business;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\ReportMetricsService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_net_subtracts_refunds_and_groups_card_with_other(): void
    {
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $business = Business::factory()->create(['owner_id' => $user->id, 'currency' => 'UGX']);
        $user->forceFill(['business_id' => $business->id])->save();

        $sale = Sale::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'receipt_number' => 'NET-001',
            'subtotal' => 10000,
            'total_amount' => 10000,
            'payment_method' => 'cash',
            'sale_date' => now(),
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => 'Item',
            'product_price' => 10000,
            'quantity' => 1,
            'unit_price' => 10000,
            'subtotal' => 10000,
            'refunded_amount' => 1500,
            'refunded_quantity' => 1,
        ]);

        $service = app(ReportMetricsService::class);

        $this->assertSame(8500.0, $service->saleNet($sale->fresh('saleItems')));
        $this->assertSame('card_other', $service->normalizePaymentMethod('card'));
        $this->assertSame('card_other', $service->normalizePaymentMethod('other'));
        $this->assertSame('Card / Other', $service->paymentMethodLabel('card_other'));
    }
}
