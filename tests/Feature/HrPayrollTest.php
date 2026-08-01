<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsAccounting;
use Tests\TestCase;

class HrPayrollTest extends TestCase
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

    public function test_pay_run_post_creates_split_liability_journal(): void
    {
        $this->seedAccountingForBusiness($this->business);

        $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-GL-1',
            'first_name' => 'Pay',
            'last_name' => 'Roll',
            'status' => 'active',
        ])->assertCreated();

        $this->authJson('POST', '/api/v1/hr/payroll/structures', [
            'name' => 'GL Structure',
            'currency' => 'UGX',
        ])->assertCreated();

        $structureId = $this->authJson('GET', '/api/v1/hr/payroll/structures')->json('data.0.id');
        $employeeId = $this->authJson('GET', '/api/v1/hr/employees')->json('data.0.id');

        $this->authJson('POST', '/api/v1/hr/payroll/compensations', [
            'employee_id' => $employeeId,
            'structure_id' => $structureId,
            'basic_salary' => 1000000,
            'allowances_json' => [],
            'deductions_json' => [],
            'effective_from' => '2026-01-01',
        ])->assertCreated();

        $payRun = $this->authJson('POST', '/api/v1/hr/payroll/pay-runs', [
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ])->assertCreated()->json('data');

        $this->authJson('POST', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}/calculate")->assertOk();
        $this->authJson('POST', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}/approve")->assertOk();

        $posted = $this->authJson('POST', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}/post")
            ->assertOk()
            ->json('data');

        $this->assertSame('posted', $posted['status']);
        $this->assertNotNull($posted['posted_journal_entry_id']);
        $this->assertStringContainsString('2110', (string) $posted['posting_note']);

        $entry = JournalEntry::query()->findOrFail($posted['posted_journal_entry_id']);
        $codes = JournalEntryLine::query()
            ->where('entry_id', $entry->id)
            ->with('chartOfAccount:id,code')
            ->get()
            ->pluck('chartOfAccount.code')
            ->filter()
            ->values()
            ->all();

        $this->assertContains('6101', $codes);
        $this->assertContains('2110', $codes);
        $this->assertContains('2111', $codes);
        $this->assertContains('2112', $codes);
        $this->assertNotContains('2103', $codes);

        // Idempotent re-post
        $again = $this->authJson('POST', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}/post")
            ->assertOk()
            ->json('data');
        $this->assertSame($posted['posted_journal_entry_id'], $again['posted_journal_entry_id']);
    }

    public function test_pay_run_post_fails_hard_without_open_period(): void
    {
        // Seed COA but only open a period that does NOT cover the pay run.
        (new \Database\Seeders\DefaultAccountingTemplateSeeder())->run();
        \App\Models\AccountingPeriod::create([
            'business_id' => $this->business->id,
            'name' => 'Feb 2026 only',
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'is_closed' => false,
        ]);

        $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-GL-FAIL',
            'first_name' => 'No',
            'last_name' => 'Period',
            'status' => 'active',
        ])->assertCreated();

        $employeeId = $this->authJson('GET', '/api/v1/hr/employees')->json('data.0.id');

        $this->authJson('POST', '/api/v1/hr/payroll/compensations', [
            'employee_id' => $employeeId,
            'basic_salary' => 500000,
            'effective_from' => '2025-01-01',
        ])->assertCreated();

        $payRun = $this->authJson('POST', '/api/v1/hr/payroll/pay-runs', [
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
        ])->assertCreated()->json('data');

        $calculated = $this->authJson('POST', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}/calculate")
            ->assertOk()
            ->json('data');
        $this->assertNotEmpty($calculated['lines']);

        $this->authJson('POST', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}/approve")->assertOk();

        $this->authJson('POST', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}/post")
            ->assertStatus(422);

        $detail = $this->authJson('GET', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}")
            ->assertOk()
            ->json('data');

        $this->assertSame('approved', $detail['status']);
        $this->assertNull($detail['posted_journal_entry_id']);
        $this->assertNotEmpty($detail['posting_note']);
    }

    public function test_pay_run_settle_remit_and_void(): void
    {
        $this->seedAccountingForBusiness($this->business);

        $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-GL-2',
            'first_name' => 'Settle',
            'last_name' => 'Void',
            'status' => 'active',
        ])->assertCreated();

        $employeeId = $this->authJson('GET', '/api/v1/hr/employees')->json('data.0.id');
        $this->authJson('POST', '/api/v1/hr/payroll/compensations', [
            'employee_id' => $employeeId,
            'basic_salary' => 1000000,
            'effective_from' => '2026-01-01',
        ])->assertCreated();

        $payRun = $this->authJson('POST', '/api/v1/hr/payroll/pay-runs', [
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ])->assertCreated()->json('data');

        $this->authJson('POST', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}/calculate")->assertOk();
        $this->authJson('POST', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}/approve")->assertOk();
        $this->authJson('POST', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}/post")
            ->assertOk()
            ->json('data');

        $settled = $this->authJson('POST', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}/settle", [
            'funding_account_code' => '1102',
        ])->assertOk()->json('data');

        $this->assertNotNull($settled['settlement_journal_entry_id']);
        $this->assertNotNull($settled['net_settled_at']);

        // Idempotent settle
        $settledAgain = $this->authJson('POST', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}/settle")
            ->assertOk()
            ->json('data');
        $this->assertSame($settled['settlement_journal_entry_id'], $settledAgain['settlement_journal_entry_id']);

        $remitted = $this->authJson('POST', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}/remit-statutory")
            ->assertOk()
            ->json('data');
        $this->assertNotNull($remitted['statutory_journal_entry_id']);
        $this->assertNotNull($remitted['statutory_remitted_at']);

        $voided = $this->authJson('POST', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}/void")
            ->assertOk()
            ->json('data');

        $this->assertSame('void', $voided['status']);
        $this->assertNotNull($voided['voided_at']);

        $reversals = JournalEntry::query()
            ->where('business_id', $this->business->id)
            ->where('description', 'like', 'Reversing entry for%')
            ->count();
        $this->assertGreaterThanOrEqual(3, $reversals);

        // Cannot settle after void
        $this->authJson('POST', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}/settle")
            ->assertStatus(422);
    }

    public function test_update_and_delete_leave_type(): void
    {
        $type = $this->authJson('POST', '/api/v1/hr/leave/types', [
            'name' => 'Sick Leave',
            'code' => 'SL',
            'paid' => true,
            'days_per_year' => 10,
            'requires_approval' => true,
        ])->assertCreated()->json('data');

        $updated = $this->authJson('PATCH', "/api/v1/hr/leave/types/{$type['id']}", [
            'name' => 'Medical Leave',
            'days_per_year' => 12,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Medical Leave')
            ->json('data');
        $this->assertEqualsWithDelta(12.0, (float) $updated['days_per_year'], 0.01);

        $this->authJson('DELETE', "/api/v1/hr/leave/types/{$type['id']}")
            ->assertNoContent();

        $ids = collect($this->authJson('GET', '/api/v1/hr/leave/types')->assertOk()->json('data'))
            ->pluck('id');
        $this->assertFalse($ids->contains($type['id']));
    }

    public function test_leave_cancel_self_and_full_hr(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['hr'],
            'name' => 'Leave Self',
        ]);
        $staffToken = $staff->createToken('staff')->plainTextToken;

        $selfEmployee = $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-LEAVE-SELF',
            'first_name' => 'Leave',
            'last_name' => 'Self',
            'status' => 'active',
            'user_id' => $staff->id,
        ])->assertCreated()->json('data');

        $otherEmployee = $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-LEAVE-OTHER',
            'first_name' => 'Leave',
            'last_name' => 'Other',
            'status' => 'active',
        ])->assertCreated()->json('data');

        $type = $this->authJson('POST', '/api/v1/hr/leave/types', [
            'name' => 'Annual',
            'code' => 'AN',
            'paid' => true,
            'days_per_year' => 21,
            'requires_approval' => true,
        ])->assertCreated()->json('data');

        $ownRequest = $this->authJson('POST', '/api/v1/hr/leave/requests', [
            'employee_id' => $selfEmployee['id'],
            'leave_type_id' => $type['id'],
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-02',
            'days' => 2,
        ], $staffToken)->assertCreated()->json('data');

        $otherRequest = $this->authJson('POST', '/api/v1/hr/leave/requests', [
            'employee_id' => $otherEmployee['id'],
            'leave_type_id' => $type['id'],
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-11',
            'days' => 2,
        ])->assertCreated()->json('data');

        $this->authJson('POST', "/api/v1/hr/leave/requests/{$otherRequest['id']}/cancel", [], $staffToken)
            ->assertStatus(403);

        $this->authJson('POST', "/api/v1/hr/leave/requests/{$ownRequest['id']}/cancel", [], $staffToken)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->authJson('POST', "/api/v1/hr/leave/requests/{$otherRequest['id']}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_delete_salary_structure_and_compensation(): void
    {
        $structure = $this->authJson('POST', '/api/v1/hr/payroll/structures', [
            'name' => 'Standard UGX',
            'currency' => 'UGX',
        ])->assertCreated()->json('data');

        $employee = $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-COMP-DEL',
            'first_name' => 'Comp',
            'last_name' => 'Delete',
            'status' => 'active',
        ])->assertCreated()->json('data');

        $comp = $this->authJson('POST', '/api/v1/hr/payroll/compensations', [
            'employee_id' => $employee['id'],
            'structure_id' => $structure['id'],
            'basic_salary' => 800000,
            'effective_from' => '2026-01-01',
        ])->assertCreated()->json('data');

        $this->authJson('DELETE', "/api/v1/hr/payroll/structures/{$structure['id']}")
            ->assertNoContent();

        $structureIds = collect($this->authJson('GET', '/api/v1/hr/payroll/structures')->assertOk()->json('data'))
            ->pluck('id');
        $this->assertFalse($structureIds->contains($structure['id']));

        $this->authJson('DELETE', "/api/v1/hr/payroll/compensations/{$comp['id']}")
            ->assertNoContent();

        $compIds = collect($this->authJson('GET', '/api/v1/hr/payroll/compensations')->assertOk()->json('data'))
            ->pluck('id');
        $this->assertFalse($compIds->contains($comp['id']));
    }

    public function test_update_and_delete_draft_pay_run(): void
    {
        $payRun = $this->authJson('POST', '/api/v1/hr/payroll/pay-runs', [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ])->assertCreated()->json('data');

        $this->authJson('PATCH', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}", [
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ])
            ->assertOk();

        $updated = $this->authJson('GET', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}")
            ->assertOk()
            ->json('data');
        $this->assertStringStartsWith('2026-08-01', (string) $updated['period_start']);
        $this->assertStringStartsWith('2026-08-31', (string) $updated['period_end']);

        $this->authJson('DELETE', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}")
            ->assertNoContent();

        $this->authJson('GET', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}")
            ->assertStatus(404);
    }
}
