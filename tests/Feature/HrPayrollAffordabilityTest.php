<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsAccounting;
use Tests\TestCase;

class HrPayrollAffordabilityTest extends TestCase
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
        $this->seed(SystemRoleSeeder::class);

        $this->owner = User::factory()->create([
            'is_active' => true,
            'modules' => ['hr', 'hr_full', 'settings'],
        ]);

        $this->business = Business::factory()->create([
            'owner_id' => $this->owner->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);

        $this->owner->update(['business_id' => $this->business->id]);

        $this->ensureSubscription($this->business->id, \App\Models\Plan::where('slug', 'enterprise')->first()?->id);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;
    }

    protected function authJson(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $token ??= $this->ownerToken;

        // Ensure prior requests do not leave a sticky authenticated user in the app container.
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)
            ->json($method, $uri, $data);
    }

    public function test_payroll_affordability_returns_cash_vs_burn(): void
    {
        $period = $this->seedAccountingForBusiness($this->business);

        $employee = $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-AFF-1',
            'first_name' => 'Afford',
            'last_name' => 'Able',
            'status' => 'active',
        ])->assertCreated()->json('data');

        $this->authJson('POST', '/api/v1/hr/payroll/compensations', [
            'employee_id' => $employee['id'],
            'basic_salary' => 1000000,
            'allowances_json' => [],
            'deductions_json' => [],
            'effective_from' => '2026-01-01',
        ])->assertCreated();

        $cashAccount = \App\Models\ChartOfAccount::where('business_id', $this->business->id)->where('code', '1101')->firstOrFail();
        $bankAccount = \App\Models\ChartOfAccount::where('business_id', $this->business->id)->where('code', '1102')->firstOrFail();

        \App\Models\GeneralLedger::create([
            'business_id' => $this->business->id,
            'account_id' => $cashAccount->id,
            'period_id' => $period->id,
            'opening_balance' => 0,
            'total_debits' => 5000000,
            'total_credits' => 0,
            'closing_balance' => 5000000,
        ]);
        \App\Models\GeneralLedger::create([
            'business_id' => $this->business->id,
            'account_id' => $bankAccount->id,
            'period_id' => $period->id,
            'opening_balance' => 0,
            'total_debits' => 3000000,
            'total_credits' => 0,
            'closing_balance' => 3000000,
        ]);

        $data = $this->authJson('POST', '/api/v1/hr/reports/payroll-affordability', [
            'as_of_date' => now()->toDateString(),
            'horizon_months' => 3,
        ])
            ->assertOk()
            ->json('data');

        $this->assertSame(5000000.0, (float) $data['cash']['cash_1101']);
        $this->assertSame(3000000.0, (float) $data['cash']['bank_1102']);
        $this->assertSame(8000000.0, (float) $data['cash']['cash_available']);
        $this->assertGreaterThanOrEqual(1, $data['burn']['employee_count']);
        $this->assertGreaterThan(0, (float) $data['burn']['monthly_burn']);
        // monthly_burn = gross + nssf_employer; nssf_employer = 10% of basic
        $this->assertEqualsWithDelta(1100000.0, (float) $data['burn']['monthly_burn'], 0.01);
        $this->assertArrayHasKey('coverage', $data);
        $this->assertArrayHasKey('status', $data['coverage']);
        $this->assertCount(3, $data['months']);
        $this->assertNull($data['hire_scenario']);
        $this->assertSame($period->id, $data['period']['id']);
    }

    public function test_payroll_affordability_hire_increases_burn(): void
    {
        $this->seedAccountingForBusiness($this->business);

        $employee = $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-AFF-2',
            'first_name' => 'Base',
            'last_name' => 'Staff',
            'status' => 'active',
        ])->assertCreated()->json('data');

        $this->authJson('POST', '/api/v1/hr/payroll/compensations', [
            'employee_id' => $employee['id'],
            'basic_salary' => 1000000,
            'effective_from' => '2026-01-01',
        ])->assertCreated();

        $baseline = $this->authJson('POST', '/api/v1/hr/reports/payroll-affordability', [
            'horizon_months' => 3,
        ])->assertOk()->json('data');

        $withHire = $this->authJson('POST', '/api/v1/hr/reports/payroll-affordability', [
            'horizon_months' => 3,
            'hire' => [
                'basic_salary' => 2000000,
                'allowances' => [],
                'deductions' => [],
                'start_month_offset' => 0,
            ],
        ])->assertOk()->json('data');

        $this->assertNotNull($withHire['hire_scenario']);
        $this->assertGreaterThan(
            (float) $baseline['burn']['monthly_burn'],
            (float) $withHire['hire_scenario']['incremental_monthly_burn'] + (float) $baseline['burn']['monthly_burn'] - 0.01,
        );
        // Hire incremental = 2_000_000 * 1.10 = 2_200_000
        $this->assertEqualsWithDelta(2200000.0, (float) $withHire['hire_scenario']['incremental_monthly_burn'], 0.01);
        $this->assertGreaterThan(
            (float) $baseline['months'][0]['need'],
            (float) $withHire['hire_scenario']['months'][0]['need'],
        );
    }

    public function test_payroll_affordability_forbidden_without_hr_full(): void
    {
        $this->seedAccountingForBusiness($this->business);
        $this->owner->update(['modules' => ['hr', 'settings']]);

        $this->authJson('POST', '/api/v1/hr/reports/payroll-affordability', [
            'horizon_months' => 3,
        ])->assertStatus(403);
    }

    public function test_payroll_affordability_422_without_period(): void
    {
        // COA only — no accounting period for this business.
        (new \Database\Seeders\DefaultAccountingTemplateSeeder())->run();

        $this->authJson('POST', '/api/v1/hr/reports/payroll-affordability', [
            'horizon_months' => 3,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period_id']);
    }
}
