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

        $types = [
            ['name' => 'Asset', 'normal_balance' => 'debit'],
            ['name' => 'Liability', 'normal_balance' => 'credit'],
            ['name' => 'Equity', 'normal_balance' => 'credit'],
            ['name' => 'Revenue', 'normal_balance' => 'credit'],
            ['name' => 'Expense', 'normal_balance' => 'debit'],
        ];
        foreach ($types as $t) { AccountType::create($t); }

        $at = fn($n) => AccountType::where('name', $n)->first()->id;

        ChartOfAccount::insert([
            ['business_id' => $this->business->id, 'code' => '1101', 'name' => 'Cash', 'type_id' => $at('Asset'), 'normal_balance' => 'debit', 'is_active' => true, 'is_system' => true],
            ['business_id' => $this->business->id, 'code' => '1104', 'name' => 'Inventory', 'type_id' => $at('Asset'), 'normal_balance' => 'debit', 'is_active' => true, 'is_system' => true],
            ['business_id' => $this->business->id, 'code' => '2101', 'name' => 'AP', 'type_id' => $at('Liability'), 'normal_balance' => 'credit', 'is_active' => true, 'is_system' => true],
            ['business_id' => $this->business->id, 'code' => '4100', 'name' => 'Sales Revenue', 'type_id' => $at('Revenue'), 'normal_balance' => 'credit', 'is_active' => true, 'is_system' => true],
            ['business_id' => $this->business->id, 'code' => '6101', 'name' => 'Salaries', 'type_id' => $at('Expense'), 'normal_balance' => 'debit', 'is_active' => true, 'is_system' => true],
            ['business_id' => $this->business->id, 'code' => '5100', 'name' => 'COGS', 'type_id' => $at('Expense'), 'normal_balance' => 'debit', 'is_active' => true, 'is_system' => true],
            ['business_id' => $this->business->id, 'code' => '3200', 'name' => 'Retained Earnings', 'type_id' => $at('Equity'), 'normal_balance' => 'credit', 'is_active' => true, 'is_system' => true],
        ]);

        $this->period = AccountingPeriod::create([
            'business_id' => $this->business->id, 'name' => 'Test Period',
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(), 'is_closed' => false,
        ]);

        $journalService = app(JournalEntryService::class);
        $ledgerService = app(LedgerService::class);

        $codes = ['1101' => 'debit', '4100' => 'credit'];
        $cashAcct = ChartOfAccount::where('business_id', $this->business->id)->where('code', '1101')->first();
        $revAcct = ChartOfAccount::where('business_id', $this->business->id)->where('code', '4100')->first();
        $salAcct = ChartOfAccount::where('business_id', $this->business->id)->where('code', '6101')->first();
        $cogsAcct = ChartOfAccount::where('business_id', $this->business->id)->where('code', '5100')->first();

        $entry = $journalService->createEntry(
            $this->business->id, now()->toDateString(), 'Revenue',
            [
                ['account_id' => $cashAcct->id, 'debit' => 20000, 'credit' => 0],
                ['account_id' => $revAcct->id, 'debit' => 0, 'credit' => 20000],
            ],
        );
        $journalService->postEntry($entry->id);

        $entry2 = $journalService->createEntry(
            $this->business->id, now()->toDateString(), 'Salaries',
            [
                ['account_id' => $salAcct->id, 'debit' => 5000, 'credit' => 0],
                ['account_id' => $cashAcct->id, 'debit' => 0, 'credit' => 5000],
            ],
        );
        $journalService->postEntry($entry2->id);

        $entry3 = $journalService->createEntry(
            $this->business->id, now()->toDateString(), 'COGS',
            [
                ['account_id' => $cogsAcct->id, 'debit' => 8000, 'credit' => 0],
                ['account_id' => $cashAcct->id, 'debit' => 0, 'credit' => 8000],
            ],
        );
        $journalService->postEntry($entry3->id);

        $this->service = app(RatioService::class);
    }

    public function test_liquidity_ratios(): void
    {
        $ratios = $this->service->getLiquidityRatios($this->business->id, $this->period->id);
        $this->assertArrayHasKey('current_ratio', $ratios);
        $this->assertArrayHasKey('quick_ratio', $ratios);
        $this->assertArrayHasKey('cash_ratio', $ratios);
        $this->assertNotNull($ratios['current_ratio'], 'Current ratio should be calculable');
    }

    public function test_profitability_ratios(): void
    {
        $ratios = $this->service->getProfitabilityRatios($this->business->id, $this->period->id);
        $this->assertArrayHasKey('gross_profit_margin', $ratios);
        $this->assertArrayHasKey('net_profit_margin', $ratios);
        $this->assertArrayHasKey('return_on_assets', $ratios);
        $this->assertArrayHasKey('return_on_equity', $ratios);
        $this->assertNotNull($ratios['gross_profit_margin']);
    }

    public function test_solvency_ratios(): void
    {
        $ratios = $this->service->getSolvencyRatios($this->business->id, $this->period->id);
        $this->assertArrayHasKey('debt_to_equity', $ratios);
        $this->assertArrayHasKey('debt_ratio', $ratios);
        $this->assertArrayHasKey('interest_coverage_ratio', $ratios);
    }

    public function test_efficiency_ratios(): void
    {
        $ratios = $this->service->getEfficiencyRatios($this->business->id, $this->period->id);
        $this->assertArrayHasKey('asset_turnover', $ratios);
        $this->assertArrayHasKey('inventory_turnover', $ratios);
        $this->assertArrayHasKey('accounts_receivable_turnover', $ratios);
    }

    public function test_recommendations_are_generated(): void
    {
        $result = $this->service->calculateAll($this->business->id, $this->period->id);
        $this->assertArrayHasKey('liquidity', $result);
        $this->assertArrayHasKey('profitability', $result);
        $this->assertArrayHasKey('solvency', $result);
        $this->assertArrayHasKey('efficiency', $result);
        $this->assertArrayHasKey('recommendations', $result);
        $this->assertIsArray($result['recommendations']);
    }

    public function test_division_by_zero_returns_null(): void
    {
        $empty = Business::factory()->create(['owner_id' => User::factory()->create()->id]);
        $p = AccountingPeriod::create(['business_id' => $empty->id, 'name' => 'Empty', 'start_date' => now(), 'end_date' => now()->addMonth(), 'is_closed' => false]);

        $at = fn($n) => AccountType::where('name', $n)->first()->id;
        ChartOfAccount::create(['business_id' => $empty->id, 'code' => '1101', 'name' => 'Cash', 'type_id' => $at('Asset'), 'normal_balance' => 'debit', 'is_active' => true]);
        ChartOfAccount::create(['business_id' => $empty->id, 'code' => '2101', 'name' => 'AP', 'type_id' => $at('Liability'), 'normal_balance' => 'credit', 'is_active' => true]);

        $ratios = $this->service->getLiquidityRatios($empty->id, $p->id);
        $this->assertNull($ratios['current_ratio']);
    }

    public function test_get_trends_returns_array(): void
    {
        $trends = $this->service->getTrends($this->business->id, 'monthly', 3);
        $this->assertIsArray($trends);
        if (!empty($trends)) {
            $this->assertArrayHasKey('period_id', $trends[0]);
            $this->assertArrayHasKey('ratios', $trends[0]);
        }
    }
}
