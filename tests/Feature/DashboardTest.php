<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Expense;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_returns_net_sales_after_refunds_and_expenses(): void
    {
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $business = Business::factory()->create([
            'owner_id' => $user->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $user->forceFill(['business_id' => $business->id])->save();

        $sale = Sale::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'receipt_number' => 'R-001',
            'subtotal' => 100000,
            'tax_total' => 0,
            'discount_amount' => 0,
            'total_amount' => 100000,
            'payment_method' => 'cash',
            'payment_status' => 'partially_refunded',
            'sale_date' => now(),
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => 'Test Product',
            'product_price' => 100000,
            'quantity' => 1,
            'unit_price' => 100000,
            'subtotal' => 100000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'refunded_quantity' => 1,
            'refunded_amount' => 25000,
        ]);
        Expense::create([
            'business_id' => $business->id,
            'recorded_by' => $user->id,
            'amount' => 10000,
            'description' => 'Transport',
            'expense_date' => now(),
        ]);

        Product::create([
            'business_id' => $business->id,
            'name' => 'Test Product',
            'slug' => 'test-product',
            'unit_price' => 100000,
            'cost_price' => 50000,
            'stock_quantity' => 10,
            'low_stock_threshold' => 2,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$user->createToken('dash')->plainTextToken)
            ->getJson('/api/v1/dashboard/summary');

        $response->assertStatus(200)
            ->assertJsonPath('today_gross_sales', 100000)
            ->assertJsonPath('today_refunds', 25000)
            ->assertJsonPath('today_net_after_refunds', 75000)
            ->assertJsonPath('today_net_sales', 65000)
            ->assertJsonPath('today_expenses', 10000)
            ->assertJsonPath('today_net_after_expenses', 65000)
            ->assertJsonPath('sales_trend.6.revenue', 100000)
            ->assertJsonPath('sales_trend.6.refunds', 25000)
            ->assertJsonPath('sales_trend.6.expenses', 10000)
            ->assertJsonPath('sales_trend.6.net_sales', 65000)
            ->assertJsonPath('recent_sales.0.refunds', 25000)
            ->assertJsonPath('recent_sales.0.net_amount', 75000);
    }
}
