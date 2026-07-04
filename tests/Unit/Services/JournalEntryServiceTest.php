<?php

namespace Tests\Unit\Services;

use App\Models\AccountType;
use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\JournalEntryService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalEntryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected JournalEntryService $service;

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
        $expenseType = AccountType::where('name', 'Expense')->first();

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

        ChartOfAccount::create([
            'business_id' => $this->business->id,
            'code' => '5100',
            'name' => 'Cost of Goods Sold',
            'type_id' => $expenseType->id,
            'normal_balance' => 'debit',
        ]);

        $this->period = AccountingPeriod::create([
            'business_id' => $this->business->id,
            'name' => 'Test Period',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'is_closed' => false,
        ]);

        $this->service = app(JournalEntryService::class);
    }

    public function test_creates_balanced_entry(): void
    {
        $entry = $this->service->createEntry(
            $this->business->id,
            now()->toDateString(),
            'Test entry',
            [
                ['account_code' => '1101', 'debit' => 1000, 'credit' => 0, 'description' => 'Debit'],
                ['account_code' => '4100', 'debit' => 0, 'credit' => 1000, 'description' => 'Credit'],
            ],
        );

        $this->assertNotNull($entry);
        $this->assertCount(2, $entry->lines);
        $this->assertEquals(1000, (float) $entry->lines[0]->debit_amount);
        $this->assertEquals(1000, (float) $entry->lines[1]->credit_amount);
    }

    public function test_rejects_unbalanced_entry(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not balanced');

        $this->service->createEntry(
            $this->business->id,
            now()->toDateString(),
            'Unbalanced entry',
            [
                ['account_code' => '1101', 'debit' => 1000, 'credit' => 0, 'description' => 'Debit'],
                ['account_code' => '4100', 'debit' => 0, 'credit' => 500, 'description' => 'Credit'],
            ],
        );
    }

    public function test_creates_reversing_entry(): void
    {
        $original = $this->service->createAndPostEntry(
            $this->business->id,
            now()->toDateString(),
            'Original entry',
            [
                ['account_code' => '1101', 'debit' => 500, 'credit' => 0, 'description' => 'Debit'],
                ['account_code' => '4100', 'debit' => 0, 'credit' => 500, 'description' => 'Credit'],
            ],
        );

        $reversing = $this->service->createReversingEntry($original->id);

        $this->assertNotNull($reversing);
        $this->assertNotNull($reversing->posted_at);
        $this->assertCount(2, $reversing->lines);

        $reversingDebit = $reversing->lines->firstWhere('debit_amount', '>', 0);
        $reversingCredit = $reversing->lines->firstWhere('credit_amount', '>', 0);

        $this->assertNotNull($reversingDebit);
        $this->assertNotNull($reversingCredit);
        $this->assertEquals(500, (float) $reversingDebit->debit_amount);
        $this->assertEquals(500, (float) $reversingCredit->credit_amount);
    }

    public function test_rejects_entry_in_closed_period(): void
    {
        $this->period->update(['is_closed' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('closed');

        $this->service->createEntry(
            $this->business->id,
            now()->toDateString(),
            'Entry in closed period',
            [
                ['account_code' => '1101', 'debit' => 100, 'credit' => 0, 'description' => 'Debit'],
                ['account_code' => '4100', 'debit' => 0, 'credit' => 100, 'description' => 'Credit'],
            ],
        );
    }
}
