<?php

namespace Tests\Unit\Services;

use App\Models\AccountType;
use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\FinancialStatementService;
use App\Services\JournalEntryService;
use App\Services\LedgerService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialStatementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected FinancialStatementService $service;

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
        $revenueType = AccountType::where('name', 'Revenue')->first();
        $expenseType = AccountType::where('name', 'Expense')->first();

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
        ChartOfAccount::create([
            'business_id' => $this->business->id,
            'code' => '4100', 'name' => 'Sales Revenue',
            'type_id' => $revenueType->id, 'normal_balance' => 'credit',
        ]);
        ChartOfAccount::create([
            'business_id' => $this->business->id,
            'code' => '5100', 'name' => 'COGS',
            'type_id' => $expenseType->id, 'normal_balance' => 'debit',
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
            $this->business->id, now()->toDateString(), 'Revenue',
            [
                ['account_code' => '1101', 'debit' => 10000, 'credit' => 0, 'description' => 'Cash'],
                ['account_code' => '4100', 'debit' => 0, 'credit' => 10000, 'description' => 'Revenue'],
            ],
        );

        $entry2 = $journalService->createAndPostEntry(
            $this->business->id, now()->toDateString(), 'Expense',
            [
                ['account_code' => '5100', 'debit' => 4000, 'credit' => 0, 'description' => 'COGS'],
                ['account_code' => '1101', 'debit' => 0, 'credit' => 4000, 'description' => 'Cash out'],
            ],
        );

        $this->service = app(FinancialStatementService::class);
    }

    public function test_income_statement_revenue_minus_expenses(): void
    {
        $statement = $this->service->incomeStatement($this->business->id, $this->period->id);

        $this->assertEquals(10000, $statement['total_revenue']);
        $this->assertEquals(4000, $statement['total_expenses']);
        $this->assertEquals(6000, $statement['net_income']);
    }

    public function test_balance_sheet_assets_equal_liabilities_plus_equity(): void
    {
        $sheet = $this->service->balanceSheet($this->business->id, $this->period->id);

        $this->assertEquals(6000, $sheet['total_assets']);
        $this->assertEquals($sheet['total_liabilities'] + $sheet['total_equity'], $sheet['total_liabilities_and_equity']);
        $this->assertTrue($sheet['is_balanced']);
    }
}
