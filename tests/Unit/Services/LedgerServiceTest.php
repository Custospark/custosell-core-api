<?php

namespace Tests\Unit\Services;

use App\Models\AccountType;
use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\ChartOfAccount;
use App\Models\GeneralLedger;
use App\Models\User;
use App\Services\JournalEntryService;
use App\Services\LedgerService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected JournalEntryService $journalEntryService;

    protected LedgerService $ledgerService;

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
        $revenueType = AccountType::where('name', 'Revenue')->first();

        ChartOfAccount::create([
            'business_id' => $this->business->id,
            'code' => '1101',
            'name' => 'Cash',
            'type_id' => $assetType->id,
            'normal_balance' => 'debit',
        ]);

        ChartOfAccount::create([
            'business_id' => $this->business->id,
            'code' => '4100',
            'name' => 'Sales Revenue',
            'type_id' => $revenueType->id,
            'normal_balance' => 'credit',
        ]);

        $this->period = AccountingPeriod::create([
            'business_id' => $this->business->id,
            'name' => 'Test Period',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'is_closed' => false,
        ]);

        $this->journalEntryService = app(JournalEntryService::class);
        $this->ledgerService = app(LedgerService::class);
    }

    public function test_posts_entry_to_ledger(): void
    {
        $entry = $this->journalEntryService->createAndPostEntry(
            $this->business->id,
            now()->toDateString(),
            'Test entry for ledger',
            [
                ['account_code' => '1101', 'debit' => 2000, 'credit' => 0, 'description' => 'Debit'],
                ['account_code' => '4100', 'debit' => 0, 'credit' => 2000, 'description' => 'Credit'],
            ],
        );

        $this->ledgerService->postEntryToLedger($entry->id);

        $cashLedger = GeneralLedger::where('business_id', $this->business->id)
            ->whereHas('chartOfAccount', fn ($q) => $q->where('code', '1101'))
            ->first();

        $this->assertNotNull($cashLedger);
        $this->assertEquals(2000, (float) $cashLedger->closing_balance);
    }

    public function test_calculates_account_balance(): void
    {
        $entry = $this->journalEntryService->createAndPostEntry(
            $this->business->id,
            now()->toDateString(),
            'Test for balance',
            [
                ['account_code' => '1101', 'debit' => 5000, 'credit' => 0, 'description' => 'Debit'],
                ['account_code' => '4100', 'debit' => 0, 'credit' => 5000, 'description' => 'Credit'],
            ],
        );

        $this->ledgerService->postEntryToLedger($entry->id);

        $cashAccount = ChartOfAccount::where('business_id', $this->business->id)
            ->where('code', '1101')
            ->first();

        $balance = $this->ledgerService->calculateAccountBalance($cashAccount->id, $this->business->id, $this->period->id);

        $this->assertEquals(5000, $balance);
    }

    public function test_generates_trial_balance(): void
    {
        $entry1 = $this->journalEntryService->createAndPostEntry(
            $this->business->id,
            now()->toDateString(),
            'Entry 1',
            [
                ['account_code' => '1101', 'debit' => 3000, 'credit' => 0, 'description' => 'Debit'],
                ['account_code' => '4100', 'debit' => 0, 'credit' => 3000, 'description' => 'Credit'],
            ],
        );
        $this->ledgerService->postEntryToLedger($entry1->id);

        $trialBalance = $this->ledgerService->generateTrialBalance($this->business->id, $this->period->id);

        $this->assertCount(2, $trialBalance['rows']);
        $this->assertEquals(3000, $trialBalance['total_debits']);
        $this->assertEquals(3000, $trialBalance['total_credits']);
    }
}
