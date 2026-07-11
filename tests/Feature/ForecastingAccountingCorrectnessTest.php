<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GeneralLedger;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeCompensation;
use App\Models\Sale;
use App\Models\User;
use App\Services\Forecasting\BudgetVsActualService;
use App\Services\Forecasting\CashForecastService;
use App\Services\Hr\HrPayrollAffordabilityService;
use Carbon\Carbon;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsAccounting;
use Tests\TestCase;

/**
 * Correctness tests: forecasting must match Accounting GL closing balances
 * and produce exact ladder / BvA math — not merely a non-empty payload.
 */
class ForecastingAccountingCorrectnessTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAccounting;

    protected Business $business;

    protected User $owner;

    protected string $ownerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->owner = User::factory()->create([
            'is_active' => true,
            'modules' => ['forecasting', 'hr', 'hr_full', 'accounting', 'expenses', 'sales', 'settings'],
        ]);

        $this->business = Business::factory()->create([
            'owner_id' => $this->owner->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);

        $this->owner->update(['business_id' => $this->business->id]);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;
    }

    protected function authJson(string $method, string $uri, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->ownerToken)->json($method, $uri, $data);
    }

    protected function seedGlClosing(int $periodId, string $code, float $closing): ChartOfAccount
    {
        $account = ChartOfAccount::query()
            ->where('business_id', $this->business->id)
            ->where('code', $code)
            ->firstOrFail();

        GeneralLedger::query()->updateOrCreate(
            [
                'business_id' => $this->business->id,
                'account_id' => $account->id,
                'period_id' => $periodId,
            ],
            [
                'opening_balance' => 0,
                'total_debits' => $closing > 0 && in_array($code, ['1101', '1102'], true) ? $closing : 0,
                'total_credits' => $closing > 0 && ! in_array($code, ['1101', '1102'], true) ? $closing : 0,
                'closing_balance' => $closing,
            ],
        );

        return $account;
    }

    public function test_cash_forecast_matches_gl_closing_balances_exactly(): void
    {
        $period = $this->seedAccountingForBusiness($this->business);

        $this->seedGlClosing($period->id, '1101', 4_250_000);
        $this->seedGlClosing($period->id, '1102', 7_750_000);
        $this->seedGlClosing($period->id, '2110', 1_200_000);
        $this->seedGlClosing($period->id, '2111', 300_000);
        $this->seedGlClosing($period->id, '2112', 500_000);

        $data = $this->authJson('GET', '/api/v1/forecasting/cash-forecast?period_id='.$period->id.'&horizon_months=2')
            ->assertOk()
            ->json('data');

        $this->assertSame(4_250_000.0, (float) $data['cash']['cash_1101']);
        $this->assertSame(7_750_000.0, (float) $data['cash']['bank_1102']);
        $this->assertSame(12_000_000.0, (float) $data['cash']['cash_available']);

        $this->assertSame(1_200_000.0, (float) $data['liabilities']['salaries_payable_2110']);
        $this->assertSame(300_000.0, (float) $data['liabilities']['paye_payable_2111']);
        $this->assertSame(500_000.0, (float) $data['liabilities']['nssf_payable_2112']);
        $this->assertSame(2_000_000.0, (float) $data['liabilities']['unpaid_payroll_liabilities']);

        $this->assertSame($period->id, $data['period']['id']);
        $this->assertSame(10_000_000.0, (float) $data['coverage']['cash_after_arrears']);
        $this->assertTrue($data['coverage']['can_clear_arrears']);
    }

    public function test_cash_forecast_matches_payroll_affordability_cash_and_liabilities(): void
    {
        $period = $this->seedAccountingForBusiness($this->business);
        $this->seedGlClosing($period->id, '1101', 2_000_000);
        $this->seedGlClosing($period->id, '1102', 3_000_000);
        $this->seedGlClosing($period->id, '2110', 400_000);
        $this->seedGlClosing($period->id, '2111', 100_000);
        $this->seedGlClosing($period->id, '2112', 50_000);

        $asOf = now()->toDateString();

        $afford = app(HrPayrollAffordabilityService::class)->analyze(
            $this->business->id,
            $asOf,
            $period->id,
            3,
            null,
        );

        $forecast = app(CashForecastService::class)->forecast(
            $this->business->id,
            $asOf,
            $period->id,
            3,
            null,
        );

        $this->assertSame($afford['cash']['cash_available'], $forecast['cash']['cash_available']);
        $this->assertSame($afford['cash']['cash_1101'], $forecast['cash']['cash_1101']);
        $this->assertSame($afford['cash']['bank_1102'], $forecast['cash']['bank_1102']);
        $this->assertSame(
            $afford['liabilities']['unpaid_payroll_liabilities'],
            $forecast['liabilities']['unpaid_payroll_liabilities'],
        );
        $this->assertSame($afford['period']['id'], $forecast['period']['id']);
        $this->assertSame(
            (float) $afford['burn']['monthly_burn'],
            (float) $forecast['burn']['monthly_payroll_burn'],
        );
    }

    public function test_month_ladder_math_is_exact_with_known_inputs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15'));

        $period = $this->seedAccountingForBusiness($this->business);
        $this->seedGlClosing($period->id, '1101', 5_000_000);
        $this->seedGlClosing($period->id, '1102', 5_000_000);
        $this->seedGlClosing($period->id, '2110', 1_000_000);
        $this->seedGlClosing($period->id, '2111', 0);
        $this->seedGlClosing($period->id, '2112', 0);

        $employee = HrEmployee::create([
            'business_id' => $this->business->id,
            'employee_number' => 'FC-CORR-1',
            'first_name' => 'Corr',
            'last_name' => 'Test',
            'status' => 'active',
            'employment_type' => 'full_time',
            'hire_date' => '2026-01-01',
        ]);

        HrEmployeeCompensation::create([
            'business_id' => $this->business->id,
            'employee_id' => $employee->id,
            'basic_salary' => 1_000_000,
            'allowances_json' => [],
            'deductions_json' => [],
            'effective_from' => '2026-01-01',
        ]);

        // Trailing 30d opex = 200_000; trailing sales = 800_000
        $cat = ExpenseCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Ops',
            'slug' => 'ops-corr',
        ]);
        Expense::create([
            'business_id' => $this->business->id,
            'expense_category_id' => $cat->id,
            'recorded_by' => $this->owner->id,
            'amount' => 200_000,
            'description' => 'Ops',
            'expense_date' => '2026-07-10',
        ]);
        Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'receipt_number' => 'FC-CORR-S1',
            'subtotal' => 800_000,
            'total_amount' => 800_000,
            'payment_method' => 'cash',
            'sale_date' => '2026-07-12',
        ]);

        $data = $this->authJson('GET', '/api/v1/forecasting/cash-forecast?as_of_date=2026-07-15&period_id='.$period->id.'&horizon_months=3')
            ->assertOk()
            ->json('data');

        // Payroll burn = gross 1_000_000 + employer NSSF 10% = 1_100_000
        $this->assertEqualsWithDelta(1_100_000.0, (float) $data['burn']['monthly_payroll_burn'], 0.01);
        $this->assertEqualsWithDelta(200_000.0, (float) $data['burn']['monthly_opex'], 0.01);
        $this->assertEqualsWithDelta(800_000.0, (float) $data['inflows']['assumed_monthly_inflow'], 0.01);

        $m0 = $data['months'][0];
        $m1 = $data['months'][1];

        // Month 0: opening 10M, inflow 800k, payroll 1.1M, opex 200k, clear liabilities 1M
        // net = 800000 - 1100000 - 200000 - 1000000 = -1_500_000
        // closing = 10_000_000 - 1_500_000 = 8_500_000
        $this->assertEqualsWithDelta(10_000_000.0, (float) $m0['opening_cash'], 0.01);
        $this->assertEqualsWithDelta(800_000.0, (float) $m0['inflows'], 0.01);
        $this->assertEqualsWithDelta(1_100_000.0, (float) $m0['payroll_outflow'], 0.01);
        $this->assertEqualsWithDelta(200_000.0, (float) $m0['opex_outflow'], 0.01);
        $this->assertEqualsWithDelta(1_000_000.0, (float) $m0['liability_clear'], 0.01);
        $this->assertEqualsWithDelta(-1_500_000.0, (float) $m0['net_change'], 0.01);
        $this->assertEqualsWithDelta(8_500_000.0, (float) $m0['closing_cash'], 0.01);
        $this->assertTrue($m0['can_cover']);

        // Month 1: opening 8.5M, no liability clear
        // net = 800000 - 1100000 - 200000 = -500_000
        // closing = 8_000_000
        $this->assertEqualsWithDelta(8_500_000.0, (float) $m1['opening_cash'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $m1['liability_clear'], 0.01);
        $this->assertEqualsWithDelta(-500_000.0, (float) $m1['net_change'], 0.01);
        $this->assertEqualsWithDelta(8_000_000.0, (float) $m1['closing_cash'], 0.01);

        // Net monthly burn for coverage = payroll + opex - inflow = 1.1M + 0.2M - 0.8M = 0.5M
        // cash after arrears = 10M - 1M = 9M; runway = 9M / 0.5M = 18
        $this->assertEqualsWithDelta(9_000_000.0, (float) $data['coverage']['cash_after_arrears'], 0.01);
        $this->assertEqualsWithDelta(18.0, (float) $data['coverage']['runway_months'], 0.01);
        $this->assertSame('healthy', $data['coverage']['status']);

        Carbon::setTestNow();
    }

    public function test_budget_vs_actual_variance_math_matches_period_normalization(): void
    {
        $period = $this->seedAccountingForBusiness($this->business);
        // SeedsAccounting uses current calendar month
        $start = Carbon::parse($period->start_date)->startOfDay();
        $end = Carbon::parse($period->end_date)->endOfDay();
        $days = max(1, (int) $start->diffInDays($end) + 1);

        $monthlyBudget = 300_000.0;
        $expectedBudget = round(($monthlyBudget / 30.4375) * $days, 2);
        $actualSpend = 250_000.0;
        $expectedVariance = round($actualSpend - $expectedBudget, 2);

        $category = ExpenseCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Rent',
            'slug' => 'rent-corr',
            'budget_amount' => $monthlyBudget,
            'budget_period' => 'monthly',
        ]);

        Expense::create([
            'business_id' => $this->business->id,
            'expense_category_id' => $category->id,
            'recorded_by' => $this->owner->id,
            'amount' => $actualSpend,
            'description' => 'Rent',
            'expense_date' => $start->copy()->addDays(2)->toDateString(),
        ]);

        $data = app(BudgetVsActualService::class)->analyze($this->business->id, $period->id);
        $row = collect($data['categories'])->firstWhere('expense_category_id', $category->id);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta($expectedBudget, (float) $row['budget'], 0.01);
        $this->assertEqualsWithDelta($actualSpend, (float) $row['actual'], 0.01);
        $this->assertEqualsWithDelta($expectedVariance, (float) $row['variance'], 0.01);
        $this->assertEqualsWithDelta($expectedBudget, (float) $data['totals']['budget'], 0.01);
        $this->assertEqualsWithDelta($actualSpend, (float) $data['totals']['actual'], 0.01);
    }

    public function test_scenario_extra_opex_increases_ladder_opex_exactly(): void
    {
        $period = $this->seedAccountingForBusiness($this->business);
        $this->seedGlClosing($period->id, '1101', 10_000_000);
        $this->seedGlClosing($period->id, '1102', 0);

        $baseline = $this->authJson('GET', '/api/v1/forecasting/cash-forecast?period_id='.$period->id.'&horizon_months=2')
            ->assertOk()
            ->json('data');

        $scenario = $this->authJson('POST', '/api/v1/forecasting/scenarios', [
            'name' => 'Extra rent',
            'horizon_months' => 2,
            'extra_monthly_opex' => 500_000,
            'revenue_uplift_pct' => 0,
        ])->assertCreated()->json('data');

        $run = $this->authJson('POST', '/api/v1/forecasting/scenarios/'.$scenario['id'].'/run', [
            'period_id' => $period->id,
        ])
            ->assertOk()
            ->json('data');

        $scenarioForecast = $run['scenario_forecast'];
        $baseOpex = (float) $baseline['burn']['monthly_opex'];
        $scenarioOpex = (float) $scenarioForecast['burn']['monthly_opex'];

        $this->assertEqualsWithDelta($baseOpex + 500_000.0, $scenarioOpex, 0.01);
        $this->assertEqualsWithDelta(
            (float) $baseline['months'][0]['opex_outflow'] + 500_000.0,
            (float) $scenarioForecast['months'][0]['opex_outflow'],
            0.01,
        );
        $this->assertEqualsWithDelta(500_000.0, (float) $run['delta']['monthly_total_burn'], 0.01);
        $this->assertSame(
            (float) $baseline['cash']['cash_available'],
            (float) $run['baseline']['cash']['cash_available'],
        );
    }

    public function test_gl_cash_change_moves_forecast_cash(): void
    {
        $period = $this->seedAccountingForBusiness($this->business);
        $this->seedGlClosing($period->id, '1101', 1_000_000);
        $this->seedGlClosing($period->id, '1102', 0);

        $before = $this->authJson('GET', '/api/v1/forecasting/cash-forecast?period_id='.$period->id)
            ->assertOk()
            ->json('data');
        $this->assertSame(1_000_000.0, (float) $before['cash']['cash_available']);

        $this->seedGlClosing($period->id, '1101', 1_000_000);
        $this->seedGlClosing($period->id, '1102', 2_500_000);

        $after = $this->authJson('GET', '/api/v1/forecasting/cash-forecast?period_id='.$period->id)
            ->assertOk()
            ->json('data');
        $this->assertSame(3_500_000.0, (float) $after['cash']['cash_available']);
        $this->assertNotEquals(
            (float) $before['cash']['cash_available'],
            (float) $after['cash']['cash_available'],
        );
    }
}
