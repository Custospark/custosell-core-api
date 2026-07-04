<?php

namespace Tests\Unit\Services;

use App\Models\AccountType;
use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\JournalEntryService;
use App\Services\LedgerService;
use App\Services\RatioService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatioServiceTest extends TestCase
{
    use RefreshDatabase;

    protected RatioService $service;

    protected Business $business;

    protected AccountingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create(['owner_id' => $user->id]);
        $user->forceFill(['business_id' => $this->business->id])->save();

        $assetType = AccountType::where('name', 'Asset')->first();
        $liabilityType = AccountType::where('name', 'Liability')->first();
        $equityType = AccountType::where('name', 'Equity')->first();

        ChartOfAccount::create([
            'business_id' => $this->business->id,
            'code' => '1101', 'name' => 'Cash',
            'type_id' => $assetType->id, 'normal_balance' => 'debit',
        ]);
        ChartOfAccount::create([
            'business_id' => $this->business->id,
            'code' => '2101', 'name' => 'Accounts Payable',
            'type_id' => $liabilityType->id, 'normal_balance' => 'credit',
        ]);
        ChartOfAccount::create([
            'business_id' => $this->business->id,
            'code' => '3200', 'name' => 'Retained Earnings',
            'type_id' => $equityType->id, 'normal_balance' => 'credit',
        ]);

        $this->period = AccountingPeriod::create([
            'business_id' => $this->business->id,
            'name' => 'Test Period',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'is_closed' => false,
        ]);

        $journalService = app(JournalEntryService::class);
        $ledgerService = app(LedgerService::class);

        $entry = $journalService->createAndPostEntry(
            $this->business->id, now()->toDateString(), 'Setup',
            [
                ['account_code' => '1101', 'debit' => 20000, 'credit' => 0, 'description' => 'Cash in'],
                ['account_code' => '2101', 'debit' => 0, 'credit' => 10000, 'description' => 'Payable'],
                ['account_code' => '3200', 'debit' => 0, 'credit' => 10000, 'description' => 'Equity'],
            ],
        );
        $ledgerService->postEntryToLedger($entry->id);

        $this->service = app(RatioService::class);
    }

    public function test_liquidity_ratios(): void
    {
        $ratios = $this->service->getLiquidityRatios($this->business->id, $this->period->id);

        $this->assertArrayHasKey('current_ratio', $ratios);
        $this->assertArrayHasKey('quick_ratio', $ratios);
        $this->assertArrayHasKey('cash_ratio', $ratios);
        $this->assertNotNull($ratios['current_ratio']);
        $this->assertEquals(2.0, $ratios['current_ratio']);
    }

    public function test_solvency_ratios(): void
    {
        $ratios = $this->service->getSolvencyRatios($this->business->id, $this->period->id);

        $this->assertArrayHasKey('debt_to_equity', $ratios);
        $this->assertArrayHasKey('debt_to_asset', $ratios);
        $this->assertArrayHasKey('interest_coverage_ratio', $ratios);
        $this->assertNotNull($ratios['debt_to_equity']);
        $this->assertGreaterThan(0, $ratios['debt_to_equity']);
    }

    public function test_calculate_all_returns_grouped_ratios(): void
    {
        $result = $this->service->calculateAll($this->business->id, $this->period->id);

        $this->assertArrayHasKey('liquidity', $result);
        $this->assertArrayHasKey('profitability', $result);
        $this->assertArrayHasKey('solvency', $result);
        $this->assertArrayHasKey('efficiency', $result);
        $this->assertArrayHasKey('period_id', $result);
        $this->assertEquals($this->period->id, $result['period_id']);
    }

    public function test_division_by_zero_returns_null(): void
    {
        $emptyBusiness = Business::factory()->create(['owner_id' => User::factory()->create()->id]);

        $emptyPeriod = AccountingPeriod::create([
            'business_id' => $emptyBusiness->id,
            'name' => 'Empty Period',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'is_closed' => false,
        ]);

        $assetType = AccountType::where('name', 'Asset')->first();
        $liabilityType = AccountType::where('name', 'Liability')->first();

        ChartOfAccount::create([
            'business_id' => $emptyBusiness->id,
            'code' => '1101', 'name' => 'Cash',
            'type_id' => $assetType->id, 'normal_balance' => 'debit',
        ]);
        ChartOfAccount::create([
            'business_id' => $emptyBusiness->id,
            'code' => '2101', 'name' => 'Accounts Payable',
            'type_id' => $liabilityType->id, 'normal_balance' => 'credit',
        ]);

        $result = $this->service->calculateAll($emptyBusiness->id, $emptyPeriod->id);

        $this->assertNull($result['liquidity']['current_ratio']);
        $this->assertNull($result['solvency']['debt_to_equity']);
    }

    public function test_get_trends_returns_array(): void
    {
        $trends = $this->service->getTrends($this->business->id, 'monthly', 3);

        $this->assertIsArray($trends);
        if (!empty($trends)) {
            $this->assertArrayHasKey('period_id', $trends[0]);
            $this->assertArrayHasKey('period_name', $trends[0]);
            $this->assertArrayHasKey('ratios', $trends[0]);
        }
    }
}
