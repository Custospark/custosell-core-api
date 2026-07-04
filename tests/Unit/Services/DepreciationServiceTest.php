<?php

namespace Tests\Unit\Services;

use App\Models\AccountType;
use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\ChartOfAccount;
use App\Models\FixedAsset;
use App\Models\User;
use App\Services\DepreciationService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepreciationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected DepreciationService $service;

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
        $expenseType = AccountType::where('name', 'Expense')->first();

        ChartOfAccount::create([
            'business_id' => $this->business->id,
            'code' => '1205', 'name' => 'Accumulated Depreciation',
            'type_id' => $assetType->id, 'normal_balance' => 'credit',
        ]);
        ChartOfAccount::create([
            'business_id' => $this->business->id,
            'code' => '6300', 'name' => 'Depreciation Expense',
            'type_id' => $expenseType->id, 'normal_balance' => 'debit',
        ]);

        $this->period = AccountingPeriod::create([
            'business_id' => $this->business->id,
            'name' => 'Test Period',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'is_closed' => false,
        ]);

        $this->service = app(DepreciationService::class);
    }

    public function test_straight_line_monthly_depreciation(): void
    {
        $asset = FixedAsset::create([
            'business_id' => $this->business->id,
            'account_id' => ChartOfAccount::where('code', '1205')->first()->id,
            'name' => 'Test Machine',
            'cost' => 120000,
            'salvage_value' => 0,
            'useful_life_months' => 12,
            'purchase_date' => now()->subMonth(),
            'book_value' => 120000,
            'status' => 'active',
        ]);

        $monthly = $this->service->straightLineMonthlyDepreciation($asset);

        $this->assertEquals(10000.0, $monthly);
    }

    public function test_depreciate_does_not_go_below_salvage(): void
    {
        $asset = FixedAsset::create([
            'business_id' => $this->business->id,
            'account_id' => ChartOfAccount::where('code', '1205')->first()->id,
            'name' => 'Near Salvage Asset',
            'cost' => 10500,
            'salvage_value' => 10000,
            'useful_life_months' => 1,
            'purchase_date' => now()->subMonth(),
            'book_value' => 10300,
            'status' => 'active',
        ]);

        $entry = $this->service->calculateDepreciationForPeriod($asset, $this->period);

        $this->assertNotNull($entry);
        $this->assertEquals(300, (float) $entry->amount);
        $this->assertEquals(10000, (float) $entry->book_value_after);
    }
}
