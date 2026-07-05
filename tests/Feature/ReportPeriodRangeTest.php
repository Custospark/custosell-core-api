<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\User;
use App\Services\AccountingPeriodService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsAccounting;
use Tests\TestCase;

class ReportPeriodRangeTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAccounting;

    protected Business $business;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create([
            'owner_id' => $this->user->id,
            'created_at' => now()->setYear(2024)->setMonth(3)->setDay(1),
        ]);
        $this->user->business_id = $this->business->id;
        $this->user->save();

        $this->seedAccountingForBusiness($this->business);
        Sanctum::actingAs($this->user);
    }

    public function test_accounting_periods_index_seeds_registration_year_through_next_year(): void
    {
        $response = $this->getJson('/api/v1/accounting-periods');

        $response->assertOk();

        $years = collect($response->json('data'))
            ->map(fn (array $row) => (int) substr($row['start_date'], 0, 4))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertContains(2024, $years);
        $this->assertContains((int) now()->addYear()->format('Y'), $years);
    }

    public function test_profit_loss_accepts_calendar_year_date_range(): void
    {
        app(AccountingPeriodService::class)->ensurePeriodsForBusiness($this->business->id);

        $response = $this->getJson('/api/v1/general-ledger/profit-loss?date_from=2024-01-01&date_to=2024-12-31');

        $response->assertOk();
        $response->assertJsonPath('data.period.is_range', true);
        $response->assertJsonPath('data.period.start_date', '2024-01-01');
        $this->assertStringContainsString('2024', (string) $response->json('data.period.name'));
    }

    public function test_comma_separated_period_ids_resolve_as_range(): void
    {
        app(AccountingPeriodService::class)->ensurePeriodsForBusiness($this->business->id);

        $q1 = AccountingPeriod::query()
            ->where('business_id', $this->business->id)
            ->whereYear('start_date', 2024)
            ->whereMonth('start_date', 1)
            ->firstOrFail();

        $q1b = AccountingPeriod::query()
            ->where('business_id', $this->business->id)
            ->whereYear('start_date', 2024)
            ->whereMonth('start_date', 2)
            ->firstOrFail();

        $q1c = AccountingPeriod::query()
            ->where('business_id', $this->business->id)
            ->whereYear('start_date', 2024)
            ->whereMonth('start_date', 3)
            ->firstOrFail();

        $response = $this->getJson("/api/v1/ratios?period_id={$q1->id},{$q1b->id},{$q1c->id}");

        $response->assertOk();
        $response->assertJsonPath('data.period.is_range', true);
        $response->assertJsonPath('data.period.start_date', $q1->start_date->toDateString());
    }
}
