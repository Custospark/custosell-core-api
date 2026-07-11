<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Forecasting\ForecastSnapshot;
use App\Models\GeneralLedger;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Database\Seeders\DefaultAccountingTemplateSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsAccounting;
use Tests\TestCase;

class ForecastingModuleTest extends TestCase
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
            'modules' => ['forecasting', 'settings', 'expenses', 'sales'],
        ]);

        $this->business = Business::factory()->create([
            'owner_id' => $this->owner->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);

        $this->owner->update(['business_id' => $this->business->id]);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;
    }

    protected function authJson(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $token ??= $this->ownerToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }

    public function test_forbidden_without_forecasting_module(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['sales'],
        ]);
        $token = $staff->createToken('staff')->plainTextToken;

        $this->authJson('GET', '/api/v1/forecasting/cash-forecast', [], $token)
            ->assertStatus(403);
    }

    public function test_cash_forecast_returns_structure(): void
    {
        $period = $this->seedAccountingForBusiness($this->business);

        $cashAccount = \App\Models\ChartOfAccount::where('business_id', $this->business->id)->where('code', '1101')->firstOrFail();
        $bankAccount = \App\Models\ChartOfAccount::where('business_id', $this->business->id)->where('code', '1102')->firstOrFail();

        GeneralLedger::create([
            'business_id' => $this->business->id,
            'account_id' => $cashAccount->id,
            'period_id' => $period->id,
            'opening_balance' => 0,
            'total_debits' => 4000000,
            'total_credits' => 0,
            'closing_balance' => 4000000,
        ]);
        GeneralLedger::create([
            'business_id' => $this->business->id,
            'account_id' => $bankAccount->id,
            'period_id' => $period->id,
            'opening_balance' => 0,
            'total_debits' => 2000000,
            'total_credits' => 0,
            'closing_balance' => 2000000,
        ]);

        ExpenseCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Utilities',
            'slug' => 'utilities',
        ]);

        $category = ExpenseCategory::where('business_id', $this->business->id)->where('slug', 'utilities')->firstOrFail();
        Expense::create([
            'business_id' => $this->business->id,
            'expense_category_id' => $category->id,
            'recorded_by' => $this->owner->id,
            'amount' => 150000,
            'description' => 'Power',
            'expense_date' => now()->subDays(5),
        ]);

        Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'receipt_number' => 'FC-SALE-1',
            'subtotal' => 500000,
            'total_amount' => 500000,
            'payment_method' => 'cash',
            'sale_date' => now()->subDays(3),
        ]);

        $data = $this->authJson('GET', '/api/v1/forecasting/cash-forecast?horizon_months=3')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'as_of_date',
                    'cash' => ['cash_1101', 'bank_1102', 'cash_available'],
                    'liabilities',
                    'burn' => ['monthly_payroll_burn', 'monthly_opex', 'monthly_total_burn'],
                    'inflows' => ['trailing_30d_net_sales', 'assumed_monthly_inflow'],
                    'months',
                    'assumptions',
                    'warnings',
                ],
            ])
            ->json('data');

        $this->assertSame(6000000.0, (float) $data['cash']['cash_available']);
        $this->assertSame(500000.0, (float) $data['inflows']['trailing_30d_net_sales']);
        $this->assertSame(150000.0, (float) $data['burn']['trailing_30d_opex']);
        $this->assertCount(3, $data['months']);
        $this->assertNotEmpty($data['assumptions']);
        $this->assertIsArray($data['warnings']);
    }

    public function test_cash_forecast_422_without_period(): void
    {
        (new DefaultAccountingTemplateSeeder())->run();

        $this->authJson('GET', '/api/v1/forecasting/cash-forecast?horizon_months=3')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period_id']);
    }

    public function test_budget_vs_actual_with_category_budget(): void
    {
        $period = $this->seedAccountingForBusiness($this->business);

        $category = ExpenseCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Rent',
            'slug' => 'rent-custom',
            'budget_amount' => 300000,
            'budget_period' => 'monthly',
        ]);

        Expense::create([
            'business_id' => $this->business->id,
            'expense_category_id' => $category->id,
            'recorded_by' => $this->owner->id,
            'amount' => 250000,
            'description' => 'Shop rent',
            'expense_date' => now(),
        ]);

        $data = $this->authJson('GET', '/api/v1/forecasting/budget-vs-actual?period_id='.$period->id)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'categories',
                    'totals' => ['budget', 'actual', 'variance'],
                    'assumptions',
                    'warnings',
                ],
            ])
            ->json('data');

        $row = collect($data['categories'])->firstWhere('expense_category_id', $category->id);
        $this->assertNotNull($row);
        $this->assertSame(250000.0, (float) $row['actual']);
        $this->assertGreaterThan(0, (float) $row['budget']);
        $this->assertNotEmpty($data['assumptions']);
    }

    public function test_zbb_approve_without_justification_422(): void
    {
        $budget = $this->authJson('POST', '/api/v1/forecasting/budgets', [
            'year' => 2026,
            'name' => 'FY2026',
            'status' => 'draft',
        ])->assertCreated()->json('data');

        $line = $this->authJson('POST', '/api/v1/forecasting/budgets/'.$budget['id'].'/lines', [
            'label' => 'Marketing',
            'amount' => 1000000,
        ])->assertCreated()->json('data');

        $this->authJson('POST', '/api/v1/forecasting/budgets/'.$budget['id'].'/lines/'.$line['id'].'/approve')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['justification']);
    }

    public function test_zbb_approve_with_justification_ok(): void
    {
        $budget = $this->authJson('POST', '/api/v1/forecasting/budgets', [
            'year' => 2026,
            'name' => 'FY2026 ZBB',
        ])->assertCreated()->json('data');

        $line = $this->authJson('POST', '/api/v1/forecasting/budgets/'.$budget['id'].'/lines', [
            'label' => 'Ops tools',
            'amount' => 500000,
        ])->assertCreated()->json('data');

        $this->authJson('POST', '/api/v1/forecasting/budgets/'.$budget['id'].'/lines/'.$line['id'].'/justify', [
            'justification' => 'Required for POS uptime monitoring.',
        ])
            ->assertOk()
            ->assertJsonPath('data.zbb_status', 'justified');

        $this->authJson('POST', '/api/v1/forecasting/budgets/'.$budget['id'].'/lines/'.$line['id'].'/approve')
            ->assertOk()
            ->assertJsonPath('data.zbb_status', 'approved');
    }

    public function test_roll_creates_snapshot(): void
    {
        $budget = $this->authJson('POST', '/api/v1/forecasting/budgets', [
            'year' => (int) now()->year,
            'name' => 'Rolling budget',
        ])->assertCreated()->json('data');

        $this->authJson('POST', '/api/v1/forecasting/budgets/'.$budget['id'].'/lines', [
            'label' => 'Rent',
            'amount' => 200000,
        ])->assertCreated();

        $snapshot = $this->authJson('POST', '/api/v1/forecasting/budgets/'.$budget['id'].'/roll', [
            'label' => 'Q1 roll',
        ])
            ->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'payload_json' => ['budget', 'ytd_actuals'],
                    'assumptions',
                    'warnings',
                ],
            ])
            ->json('data');

        $this->assertDatabaseHas('forecast_snapshots', [
            'id' => $snapshot['id'],
            'forecast_budget_id' => $budget['id'],
            'label' => 'Q1 roll',
        ]);
        $this->assertSame(1, ForecastSnapshot::where('forecast_budget_id', $budget['id'])->count());
    }

    public function test_kpis_retail_mode(): void
    {
        $this->seedAccountingForBusiness($this->business);

        Customer::factory()->create([
            'business_id' => $this->business->id,
            'total_purchases' => 100000,
            'last_purchase_at' => now()->subDays(10),
        ]);
        Customer::factory()->create([
            'business_id' => $this->business->id,
            'total_purchases' => 200000,
            'last_purchase_at' => now()->subDays(120),
        ]);

        Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'receipt_number' => 'KPI-1',
            'subtotal' => 80000,
            'total_amount' => 80000,
            'payment_method' => 'cash',
            'sale_date' => now()->subDays(2),
        ]);

        $data = $this->authJson('GET', '/api/v1/forecasting/kpis?mode=retail')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'resolved_mode',
                    'retail' => ['pulse_30d_net_sales', 'cac', 'ltv', 'churn_pct_90d'],
                    'burn',
                    'assumptions',
                    'warnings',
                ],
            ])
            ->json('data');

        $this->assertSame('retail', $data['resolved_mode']);
        $this->assertSame(80000.0, (float) $data['retail']['pulse_30d_net_sales']);
        $this->assertSame(150000.0, (float) $data['retail']['ltv']);
        $this->assertSame(50.0, (float) $data['retail']['churn_pct_90d']);
    }

    public function test_kpis_saas_mode_with_recurring_product(): void
    {
        $this->seedAccountingForBusiness($this->business);

        $product = Product::factory()->recurring('month')->create([
            'business_id' => $this->business->id,
            'name' => 'SaaS Seat',
            'unit_price' => 50000,
        ]);

        $customer = Customer::factory()->create([
            'business_id' => $this->business->id,
            'total_purchases' => 50000,
            'last_purchase_at' => now(),
        ]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'customer_id' => $customer->id,
            'receipt_number' => 'SAAS-1',
            'subtotal' => 50000,
            'total_amount' => 50000,
            'payment_method' => 'cash',
            'sale_date' => now()->subDays(5),
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 50000,
            'quantity' => 1,
            'unit_price' => 50000,
            'subtotal' => 50000,
        ]);

        $data = $this->authJson('GET', '/api/v1/forecasting/kpis?mode=saas')
            ->assertOk()
            ->json('data');

        $this->assertSame('saas', $data['resolved_mode']);
        $this->assertNotNull($data['saas']);
        $this->assertSame(1, $data['saas']['active_subscribers_60d']);
        $this->assertSame(50000.0, (float) $data['saas']['mrr']);
        $this->assertTrue(
            collect($data['warnings'])->contains(fn ($w) => str_contains(strtolower((string) $w), 'mrr'))
        );
    }

    public function test_scenario_run(): void
    {
        $this->seedAccountingForBusiness($this->business);

        $scenario = $this->authJson('POST', '/api/v1/forecasting/scenarios', [
            'name' => 'Hire + uplift',
            'horizon_months' => 3,
            'hire_basic_salary' => 1000000,
            'extra_monthly_opex' => 50000,
            'revenue_uplift_pct' => 10,
        ])->assertCreated()->json('data');

        $data = $this->authJson('POST', '/api/v1/forecasting/scenarios/'.$scenario['id'].'/run')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'scenario',
                    'baseline' => ['months', 'assumptions', 'warnings'],
                    'scenario_forecast' => ['months', 'burn', 'inflows'],
                    'delta',
                    'assumptions',
                    'warnings',
                ],
            ])
            ->json('data');

        $this->assertCount(3, $data['baseline']['months']);
        $this->assertCount(3, $data['scenario_forecast']['months']);
        $this->assertGreaterThan(
            (float) $data['baseline']['burn']['monthly_total_burn'],
            (float) $data['scenario_forecast']['burn']['monthly_total_burn']
        );
    }

    public function test_overview_combines_cash_and_bva(): void
    {
        $this->seedAccountingForBusiness($this->business);

        $data = $this->authJson('GET', '/api/v1/forecasting/overview?horizon_months=2')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'cash_forecast',
                    'budget_vs_actual',
                    'assumptions',
                    'warnings',
                ],
            ])
            ->json('data');

        $this->assertArrayHasKey('months', $data['cash_forecast']);
        $this->assertArrayHasKey('categories', $data['budget_vs_actual']);
    }
}
