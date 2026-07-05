<?php

namespace Tests\Unit\Services;

use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\AutomationService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsAccounting;
use Tests\TestCase;

class AutomationServiceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAccounting;

    protected AutomationService $service;

    protected Business $business;

    protected User $user;

    protected AccountingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create(['owner_id' => $this->user->id]);
        $this->period = $this->seedAccountingForBusiness($this->business);
        $this->service = app(AutomationService::class);
    }

    public function test_cash_sale_refund_credits_cash_only(): void
    {
        $sale = $this->createSaleWithItem(total: 1000, amountPaid: 1000);
        $this->service->handleSaleCreated($sale);

        $this->service->handleSaleRefunded($sale, $this->refundBatch($sale, 200));

        $entry = $this->latestRefundEntry();
        $cashId = ChartOfAccount::where('business_id', $this->business->id)->where('code', '1101')->value('id');
        $arId = ChartOfAccount::where('business_id', $this->business->id)->where('code', '1103')->value('id');

        $this->assertEquals(200.0, (float) $this->lineCredit($entry, $cashId));
        $this->assertEquals(0.0, (float) $this->lineCredit($entry, $arId));
    }

    public function test_unpaid_credit_sale_refund_credits_ar_only(): void
    {
        $sale = $this->createSaleWithItem(total: 1000, amountPaid: 0);
        $this->service->handleSaleCreated($sale);

        $this->service->handleSaleRefunded($sale, $this->refundBatch($sale, 250));

        $entry = $this->latestRefundEntry();
        $cashId = ChartOfAccount::where('business_id', $this->business->id)->where('code', '1101')->value('id');
        $arId = ChartOfAccount::where('business_id', $this->business->id)->where('code', '1103')->value('id');

        $this->assertEquals(0.0, (float) $this->lineCredit($entry, $cashId));
        $this->assertEquals(250.0, (float) $this->lineCredit($entry, $arId));
    }

    public function test_partially_paid_credit_sale_refund_splits_cash_and_ar(): void
    {
        $sale = $this->createSaleWithItem(total: 1000, amountPaid: 300);
        $this->service->handleSaleCreated($sale);

        $this->service->handleSaleRefunded($sale, $this->refundBatch($sale, 500));

        $entry = $this->latestRefundEntry();
        $cashId = ChartOfAccount::where('business_id', $this->business->id)->where('code', '1101')->value('id');
        $arId = ChartOfAccount::where('business_id', $this->business->id)->where('code', '1103')->value('id');

        $this->assertEquals(300.0, (float) $this->lineCredit($entry, $cashId));
        $this->assertEquals(200.0, (float) $this->lineCredit($entry, $arId));
    }

    public function test_multiple_refunds_receive_unique_reference_ids(): void
    {
        $sale = $this->createSaleWithItem(total: 1000, amountPaid: 1000);
        $this->service->handleSaleCreated($sale);

        $this->service->handleSaleRefunded($sale, $this->refundBatch($sale, 100));
        $this->service->handleSaleRefunded($sale, $this->refundBatch($sale, 150));

        $refs = JournalEntry::query()
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'sale_refund')
            ->pluck('reference_id')
            ->all();

        $this->assertCount(2, $refs);
        $this->assertNotEquals($refs[0], $refs[1]);
        $this->assertEquals($sale->id * 10000 + 1, min($refs));
        $this->assertEquals($sale->id * 10000 + 2, max($refs));
    }

    public function test_second_refund_uses_remaining_cash_before_ar(): void
    {
        $sale = $this->createSaleWithItem(total: 1000, amountPaid: 300);
        $this->service->handleSaleCreated($sale);

        $this->service->handleSaleRefunded($sale, $this->refundBatch($sale, 200));
        $this->service->handleSaleRefunded($sale, $this->refundBatch($sale, 200));

        $entries = JournalEntry::query()
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'sale_refund')
            ->orderBy('id')
            ->get();

        $cashId = ChartOfAccount::where('business_id', $this->business->id)->where('code', '1101')->value('id');
        $arId = ChartOfAccount::where('business_id', $this->business->id)->where('code', '1103')->value('id');

        $this->assertEquals(200.0, (float) $this->lineCredit($entries[0], $cashId));
        $this->assertEquals(100.0, (float) $this->lineCredit($entries[1], $cashId));
        $this->assertEquals(100.0, (float) $this->lineCredit($entries[1], $arId));
    }

    protected function createSaleWithItem(float $total, float $amountPaid): Sale
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'cost_price' => 50,
            'stock_quantity' => 20,
        ]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'receipt_number' => 'R-' . uniqid(),
            'subtotal' => $total,
            'tax_total' => 0,
            'discount_amount' => 0,
            'total_amount' => $total,
            'amount_paid' => $amountPaid,
            'payment_method' => 'cash',
            'payment_status' => $amountPaid >= $total - 0.01 ? 'paid' : 'partially_paid',
            'sale_date' => now(),
        ]);

        $saleItem = SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => $total,
            'quantity' => 1,
            'unit_price' => $total,
            'unit_cost' => 50,
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
        ]);

        return $sale->load('saleItems');
    }

    /**
     * @return array<int, array{saleItem: SaleItem, refundQty: int, proportionalAmount: float}>
     */
    protected function refundBatch(Sale $sale, float $amount, int $qty = 1): array
    {
        $saleItem = $sale->saleItems->first();

        return [[
            'saleItem' => $saleItem,
            'refundQty' => $qty,
            'proportionalAmount' => $amount,
        ]];
    }

    protected function latestRefundEntry(): JournalEntry
    {
        return JournalEntry::query()
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'sale_refund')
            ->latest('id')
            ->firstOrFail();
    }

    protected function lineCredit(JournalEntry $entry, int $accountId): float
    {
        return (float) JournalEntryLine::query()
            ->where('entry_id', $entry->id)
            ->where('account_id', $accountId)
            ->value('credit_amount');
    }
}
