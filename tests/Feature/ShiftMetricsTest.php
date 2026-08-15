<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Expense;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the canonical shift metrics formulas (docs/shift-sales-formulas.md):
 *
 *   gross_sales      = Σ sale.total_amount
 *   refunds          = Σ sale_items.refunded_amount
 *   shift_expenses   = Σ expense.amount (shift-scoped)
 *   cash_receipts    = Σ (cash sale total − its refunds), per sale
 *   net_sales        = gross_sales − refunds − shift_expenses
 *   cash_collected   = cash_receipts − shift_expenses
 *   cash_at_handover = opening_balance + cash_collected   (= expected_cash)
 *   variance         = counted_cash − cash_at_handover
 *
 * Worked example: opening 50k; cash 100k, mobile 80k, cash 60k (20k refunded),
 * card 40k; expenses 15k; counted cash 180k.
 */
class ShiftMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create([
            'owner_id' => $this->user->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->user->forceFill(['business_id' => $this->business->id])->save();
        $this->ensureSubscription($this->business->id);

        $role = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => ['reports.view' => true, 'sales.view' => true, 'sales.create' => true],
        ]);
        $this->user->forceFill(['role_id' => $role->id])->save();
        $this->token = $this->user->createToken('shift')->plainTextToken;
    }

    private function createShiftWithOpeningBalance(float $opening): Shift
    {
        return Shift::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'clock_in' => now(),
            'status' => 'active',
            'opening_balance' => $opening,
        ]);
    }

    private function createSale(Shift $shift, string $method, float $total, float $refunded = 0, float $amountPaid = 0): Sale
    {
        // Default to fully paid: amount_paid = total (so a refunded sale nets to
        // total − refunded). Partial tests override amount_paid + status.
        $paid = $amountPaid > 0 ? $amountPaid : $total;

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'shift_id' => $shift->id,
            'receipt_number' => 'R-'.uniqid(),
            'subtotal' => $total,
            'tax_total' => 0,
            'discount_amount' => 0,
            'total_amount' => $total,
            'amount_paid' => $paid,
            'payment_method' => $method,
            'payment_status' => $refunded > 0 ? 'partially_refunded' : ($amountPaid > 0 && $amountPaid < $total ? 'partially_paid' : 'paid'),
            'sale_date' => now(),
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => 'Item',
            'product_price' => $total,
            'quantity' => 1,
            'unit_price' => $total,
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'refunded_quantity' => $refunded > 0 ? 1 : 0,
            'refunded_amount' => $refunded,
        ]);

        return $sale;
    }

    public function test_shift_reconciliation_matches_canonical_formulas(): void
    {
        $shift = $this->createShiftWithOpeningBalance(50_000);

        // Sale 1 - cash 100k (no refund)
        $this->createSale($shift, 'cash', 100_000);
        // Sale 2 - mobile 80k (no refund)
        $this->createSale($shift, 'mobile_money', 80_000);
        // Sale 3 - cash 60k with 20k refunded
        $this->createSale($shift, 'cash', 60_000, 20_000);
        // Sale 4 - card 40k (no refund)
        $this->createSale($shift, 'card', 40_000);

        Expense::create([
            'business_id' => $this->business->id,
            'shift_id' => $shift->id,
            'recorded_by' => $this->user->id,
            'amount' => 15_000,
            'description' => 'Fuel',
            'expense_date' => now(),
        ]);

        $shift->update(['counted_cash' => 180_000, 'status' => 'completed', 'clock_out' => now()]);

        $rows = app(\App\Services\ReportMetricsService::class)->shiftReconciliation(
            $this->business->id,
            now()->toDateString(),
            now()->toDateString(),
        );

        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertEquals(280_000, (float) $row['gross_sales']);
        $this->assertEquals(20_000, (float) $row['refunds']);
        $this->assertEquals(15_000, (float) $row['shift_expenses']);
        $this->assertEquals(140_000, (float) $row['cash'], 'cash = per-sale net-after-refunds (100k + 40k)');
        $this->assertEquals(80_000, (float) $row['mobile_money']);
        $this->assertEquals(40_000, (float) $row['card_other']);
        $this->assertEquals(260_000, (float) $row['net_after_refunds']);
        $this->assertEquals(245_000, (float) $row['net_sales'], 'net_sales = gross − refunds − expenses');
        $this->assertEquals(50_000, (float) $row['opening_balance']);
        $this->assertEquals(125_000, (float) $row['cash_collected'], 'cash_collected = cash − expenses');
        $this->assertEquals(175_000, (float) $row['expected_cash'], 'expected_cash = opening + cash_collected');
        $this->assertEquals(175_000, (float) $row['cash_handover'], 'cash_handover = expected_cash (opening + cash_collected)');
        $this->assertEquals(180_000, (float) $row['counted_cash']);
        $this->assertEquals(5_000, (float) $row['variance'], 'variance = counted − expected');
    }

    public function test_shift_close_report_matches_canonical_formulas(): void
    {
        $shift = $this->createShiftWithOpeningBalance(50_000);

        $this->createSale($shift, 'cash', 100_000);
        $this->createSale($shift, 'mobile_money', 80_000);
        $this->createSale($shift, 'cash', 60_000, 20_000);
        $this->createSale($shift, 'card', 40_000);

        Expense::create([
            'business_id' => $this->business->id,
            'shift_id' => $shift->id,
            'recorded_by' => $this->user->id,
            'amount' => 15_000,
            'description' => 'Fuel',
            'expense_date' => now(),
        ]);

        $shift->update(['counted_cash' => 180_000, 'status' => 'completed', 'clock_out' => now()]);

        $report = app(\App\Services\ReportMetricsService::class)->shiftCloseReport($this->business->id, $shift->id);

        $this->assertEquals(245_000, (float) $report['net_sales']);
        $this->assertEquals(140_000, (float) $report['cash']);
        $this->assertEquals(125_000, (float) $report['cash_collected']);
        $this->assertEquals(175_000, (float) $report['expected_cash']);
        $this->assertEquals(175_000, (float) $report['cash_handover'], 'close report handover includes opening balance');
        $this->assertEquals(5_000, (float) $report['variance']);
    }

    public function test_shift_without_expenses_or_refunds_matches_basic_formulas(): void
    {
        $shift = $this->createShiftWithOpeningBalance(10_000);
        $this->createSale($shift, 'cash', 50_000);

        $shift->update(['counted_cash' => 60_000, 'status' => 'completed', 'clock_out' => now()]);

        $rows = app(\App\Services\ReportMetricsService::class)->shiftReconciliation(
            $this->business->id,
            now()->toDateString(),
            now()->toDateString(),
        );

        $row = $rows[0];

        $this->assertEquals(50_000, (float) $row['gross_sales']);
        $this->assertEquals(0, (float) $row['refunds']);
        $this->assertEquals(0, (float) $row['shift_expenses']);
        $this->assertEquals(50_000, (float) $row['cash']);
        $this->assertEquals(50_000, (float) $row['net_sales']);
        $this->assertEquals(50_000, (float) $row['cash_collected']);
        $this->assertEquals(60_000, (float) $row['cash_handover'], 'opening 10k + collected 50k');
        $this->assertEquals(60_000, (float) $row['expected_cash']);
        $this->assertEquals(0, (float) $row['variance']);
    }

    public function test_partial_payment_sale_only_counts_amount_actually_paid(): void
    {
        $shift = $this->createShiftWithOpeningBalance(0);
        // Cash sale worth 100k but only 40k was actually paid (partial).
        $this->createSale($shift, 'cash', 100_000, 0, 40_000);

        $shift->update(['counted_cash' => 40_000, 'status' => 'completed', 'clock_out' => now()]);

        $rows = app(\App\Services\ReportMetricsService::class)->shiftReconciliation(
            $this->business->id,
            now()->toDateString(),
            now()->toDateString(),
        );

        $row = $rows[0];

        $this->assertEquals(100_000, (float) $row['gross_sales']);
        $this->assertEquals(0, (float) $row['refunds']);
        // Only the actually-paid amount lands in cash - not the full 100k.
        $this->assertEquals(40_000, (float) $row['cash']);
        $this->assertEquals(40_000, (float) $row['cash_collected']);
        $this->assertEquals(40_000, (float) $row['cash_handover']);
        $this->assertEquals(0, (float) $row['variance']);
    }

    public function test_partial_payment_with_refund_nets_both(): void
    {
        $shift = $this->createShiftWithOpeningBalance(0);
        // Cash sale worth 100k, 20k refunded, only 50k actually paid.
        $this->createSale($shift, 'cash', 100_000, 20_000, 50_000);

        $shift->update(['counted_cash' => 50_000, 'status' => 'completed', 'clock_out' => now()]);

        $rows = app(\App\Services\ReportMetricsService::class)->shiftReconciliation(
            $this->business->id,
            now()->toDateString(),
            now()->toDateString(),
        );

        $row = $rows[0];

        $this->assertEquals(100_000, (float) $row['gross_sales']);
        $this->assertEquals(20_000, (float) $row['refunds']);
        $this->assertEquals(80_000, (float) $row['net_after_refunds']);
        // Not fully paid → only the paid amount counts in cash (capped at net).
        $this->assertEquals(50_000, (float) $row['cash']);
        $this->assertEquals(50_000, (float) $row['cash_collected']);
    }

    public function test_dashboard_day_metrics_uses_same_net_sales_formula(): void
    {
        // Dashboard is date-scoped (no shift): same arithmetic, no drawer metrics.
        $sale1 = $this->createSaleForDay('cash', 100_000);
        $sale2 = $this->createSaleForDay('mobile_money', 80_000);
        $sale3 = $this->createSaleForDay('cash', 60_000, 20_000);
        $sale4 = $this->createSaleForDay('card', 40_000);

        Expense::create([
            'business_id' => $this->business->id,
            'recorded_by' => $this->user->id,
            'amount' => 15_000,
            'description' => 'Fuel',
            'expense_date' => now(),
        ]);

        $metrics = app(\App\Services\ReportMetricsService::class)->dayMetrics($this->business->id, now()->toDateString());

        $this->assertEquals(280_000, (float) $metrics['gross_sales']);
        $this->assertEquals(20_000, (float) $metrics['refunds']);
        $this->assertEquals(15_000, (float) $metrics['expenses']);
        $this->assertEquals(260_000, (float) $metrics['net_after_refunds']);
        $this->assertEquals(245_000, (float) $metrics['net_sales'], 'dashboard net_sales = gross − refunds − expenses');
        $this->assertEquals(4, $metrics['transactions']);
    }

    public function test_dashboard_payment_breakdown_counts_partial_payments_as_collected(): void
    {
        $this->createSaleForDay('cash', 100_000, 0);
        $this->createSaleForDay('cash', 100_000, 0, 40_000); // partial: only 40k collected

        $breakdown = app(\App\Services\ReportMetricsService::class)->paymentBreakdown(
            $this->business->id,
            now()->toDateString(),
            now()->toDateString(),
        );

        $cash = collect($breakdown)->firstWhere('method', 'cash');

        $this->assertEquals(200_000, (float) $cash['gross']);
        $this->assertEquals(0, (float) $cash['refunds']);
        $this->assertEquals(140_000, (float) $cash['net'], 'cash net = 100k (full) + 40k (partial actually paid)');
    }

    private function createSaleForDay(string $method, float $total, float $refunded = 0, float $amountPaid = 0): Sale
    {
        $paid = $amountPaid > 0 ? $amountPaid : $total;

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'receipt_number' => 'R-DAY-'.uniqid(),
            'subtotal' => $total,
            'tax_total' => 0,
            'discount_amount' => 0,
            'total_amount' => $total,
            'amount_paid' => $paid,
            'payment_method' => $method,
            'payment_status' => $refunded > 0 ? 'partially_refunded' : ($amountPaid > 0 && $amountPaid < $total ? 'partially_paid' : 'paid'),
            'sale_date' => now(),
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => 'Item',
            'product_price' => $total,
            'quantity' => 1,
            'unit_price' => $total,
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'refunded_quantity' => $refunded > 0 ? 1 : 0,
            'refunded_amount' => $refunded,
        ]);

        return $sale;
    }
}
