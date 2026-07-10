<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Hr\HrAuditLog;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsAccounting;
use Tests\TestCase;

class HrModuleTest extends TestCase
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

    public function test_staff_without_hr_module_gets_403(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['sales'],
        ]);
        $token = $staff->createToken('staff')->plainTextToken;

        $this->authJson('GET', '/api/v1/hr/departments', [], $token)
            ->assertStatus(403);
    }

    public function test_create_department_position_employee_and_audit(): void
    {
        $dept = $this->authJson('POST', '/api/v1/hr/departments', [
            'name' => 'Operations',
            'description' => 'Ops team',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Operations')
            ->json('data');

        $position = $this->authJson('POST', '/api/v1/hr/positions', [
            'title' => 'Cashier',
            'department_id' => $dept['id'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Cashier')
            ->json('data');

        $employee = $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-001',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'department_id' => $dept['id'],
            'position_id' => $position['id'],
            'employment_type' => 'full_time',
            'status' => 'active',
            'hire_date' => '2026-01-15',
        ])
            ->assertCreated()
            ->assertJsonPath('data.employee_number', 'EMP-001')
            ->assertJsonPath('data.first_name', 'Jane')
            ->json('data');

        $this->assertDatabaseHas('hr_audit_logs', [
            'business_id' => $this->business->id,
            'action' => 'employee.created',
            'subject_type' => 'hr_employee',
            'subject_id' => $employee['id'],
        ]);

        $this->assertTrue(
            HrAuditLog::query()
                ->where('business_id', $this->business->id)
                ->where('action', 'employee.created')
                ->where('subject_id', $employee['id'])
                ->exists()
        );
    }

    public function test_link_employee_to_user(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['hr'],
            'name' => 'Linked Staff',
        ]);

        $employee = $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-002',
            'first_name' => 'Link',
            'last_name' => 'Me',
            'status' => 'active',
        ])->assertCreated()->json('data');

        $this->authJson('POST', "/api/v1/hr/employees/{$employee['id']}/link-user", [
            'user_id' => $staff->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.user_id', $staff->id)
            ->assertJsonPath('data.user.id', $staff->id);
    }

    public function test_create_staff_mirrors_hr_employee(): void
    {
        $role = \App\Models\Role::query()->firstOrFail();

        $this->authJson('POST', '/api/v1/users', [
            'name' => 'Mirrored Staff',
            'email' => 'mirrored.staff@example.com',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
            'role_id' => $role->id,
            'modules' => ['sales'],
            'business_id' => $this->business->id,
        ])->assertCreated();

        $user = User::query()->where('email', 'mirrored.staff@example.com')->firstOrFail();

        $this->assertDatabaseHas('hr_employees', [
            'business_id' => $this->business->id,
            'user_id' => $user->id,
            'employee_number' => 'STF-'.$user->id,
            'first_name' => 'Mirrored',
            'last_name' => 'Staff',
        ]);
    }

    public function test_employees_index_backfills_existing_staff(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['sales'],
            'name' => 'Backfill Me',
            'email' => 'backfill.me@example.com',
        ]);

        $this->assertDatabaseMissing('hr_employees', [
            'business_id' => $this->business->id,
            'user_id' => $staff->id,
        ]);

        $this->authJson('GET', '/api/v1/hr/employees')
            ->assertOk();

        $this->assertDatabaseHas('hr_employees', [
            'business_id' => $this->business->id,
            'user_id' => $staff->id,
            'first_name' => 'Backfill',
            'last_name' => 'Me',
        ]);

        $totalBefore = \App\Models\Hr\HrEmployee::query()
            ->where('business_id', $this->business->id)
            ->count();

        $this->authJson('POST', '/api/v1/hr/employees/sync-staff')
            ->assertOk()
            ->assertJsonPath('data.created', 0);

        $this->assertSame(
            $totalBefore,
            \App\Models\Hr\HrEmployee::query()->where('business_id', $this->business->id)->count()
        );
    }

    public function test_create_employee_with_account(): void
    {
        $role = \App\Models\Role::query()->firstOrFail();

        $employee = $this->authJson('POST', '/api/v1/hr/employees/with-account', [
            'employee_number' => 'EMP-ACC-1',
            'first_name' => 'Ada',
            'last_name' => 'Okello',
            'email' => 'ada.okello@example.com',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
            'role_id' => $role->id,
            'modules' => ['hr', 'sales'],
            'employment_type' => 'full_time',
            'status' => 'active',
        ])
            ->assertCreated()
            ->assertJsonPath('data.employee_number', 'EMP-ACC-1')
            ->assertJsonPath('data.user.email', 'ada.okello@example.com')
            ->json('data');

        $this->assertNotNull($employee['user_id']);
        $this->assertDatabaseHas('users', [
            'id' => $employee['user_id'],
            'email' => 'ada.okello@example.com',
        ]);
    }

    public function test_create_employee_with_account_rejects_duplicate_email(): void
    {
        $role = \App\Models\Role::query()->firstOrFail();

        User::factory()->create([
            'business_id' => $this->business->id,
            'email' => 'taken@example.com',
        ]);

        $this->authJson('POST', '/api/v1/hr/employees/with-account', [
            'employee_number' => 'EMP-DUP',
            'first_name' => 'Dup',
            'last_name' => 'Email',
            'email' => 'taken@example.com',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
            'role_id' => $role->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('hr_employees', [
            'business_id' => $this->business->id,
            'employee_number' => 'EMP-DUP',
        ]);
    }

    public function test_create_account_for_existing_employee_and_remove_account(): void
    {
        $role = \App\Models\Role::query()->firstOrFail();

        $employee = $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-LOGIN',
            'first_name' => 'No',
            'last_name' => 'Login',
            'status' => 'active',
        ])->assertCreated()->json('data');

        $linked = $this->authJson('POST', "/api/v1/hr/employees/{$employee['id']}/create-account", [
            'email' => 'nologin.now@example.com',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
            'role_id' => $role->id,
            'modules' => ['sales'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'nologin.now@example.com')
            ->json('data');

        $userId = $linked['user_id'];
        $this->assertNotNull($userId);

        $this->authJson('POST', "/api/v1/hr/employees/{$employee['id']}/remove-account")
            ->assertOk()
            ->assertJsonPath('data.user_id', null);

        $this->assertSoftDeleted('users', ['id' => $userId]);
        $this->assertDatabaseHas('hr_employees', [
            'id' => $employee['id'],
            'user_id' => null,
        ]);
    }

    public function test_unlink_user_keeps_staff_account(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['hr'],
            'name' => 'Unlink Keep',
        ]);

        $employee = $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-UNLINK',
            'first_name' => 'Unlink',
            'last_name' => 'Keep',
            'status' => 'active',
            'user_id' => $staff->id,
        ])->assertCreated()->json('data');

        $this->authJson('POST', "/api/v1/hr/employees/{$employee['id']}/unlink-user")
            ->assertOk()
            ->assertJsonPath('data.user_id', null);

        $this->assertDatabaseHas('users', ['id' => $staff->id]);
    }

    public function test_clock_in_and_out(): void
    {
        $employee = $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-003',
            'first_name' => 'Clock',
            'last_name' => 'Worker',
            'status' => 'active',
        ])->assertCreated()->json('data');

        $this->authJson('POST', '/api/v1/hr/attendance/clock', [
            'employee_id' => $employee['id'],
            'type' => 'clock_in',
            'occurred_at' => '2026-07-10T08:00:00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'clock_in');

        $this->authJson('POST', '/api/v1/hr/attendance/clock', [
            'employee_id' => $employee['id'],
            'type' => 'clock_out',
            'occurred_at' => '2026-07-10T17:00:00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'clock_out');

        $this->authJson('GET', '/api/v1/hr/attendance/register?employee_id='.$employee['id'].'&date_from=2026-07-10&date_to=2026-07-10')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'present')
            ->assertJsonPath('data.0.minutes_worked', 540);
    }

    public function test_leave_request_approve(): void
    {
        $employee = $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-004',
            'first_name' => 'Leave',
            'last_name' => 'Taker',
            'status' => 'active',
        ])->assertCreated()->json('data');

        $type = $this->authJson('POST', '/api/v1/hr/leave/types', [
            'name' => 'Annual Leave',
            'code' => 'AL',
            'paid' => true,
            'days_per_year' => 21,
            'requires_approval' => true,
        ])->assertCreated()->json('data');

        $request = $this->authJson('POST', '/api/v1/hr/leave/requests', [
            'employee_id' => $employee['id'],
            'leave_type_id' => $type['id'],
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-03',
            'days' => 3,
            'reason' => 'Family trip',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data');

        $this->authJson('POST', "/api/v1/hr/leave/requests/{$request['id']}/approve", [
            'review_note' => 'Approved',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_compensation_and_pay_run_calculate_net(): void
    {
        $employee = $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-005',
            'first_name' => 'Pay',
            'last_name' => 'Roll',
            'status' => 'active',
        ])->assertCreated()->json('data');

        $this->authJson('POST', '/api/v1/hr/payroll/compensations', [
            'employee_id' => $employee['id'],
            'basic_salary' => 1000000,
            'allowances_json' => [],
            'deductions_json' => [],
            'effective_from' => '2026-01-01',
        ])->assertCreated();

        $payRun = $this->authJson('POST', '/api/v1/hr/payroll/pay-runs', [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ])->assertCreated()->json('data');

        $calculated = $this->authJson('POST', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}/calculate")
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($calculated['lines']);
        $line = collect($calculated['lines'])->firstWhere('employee_id', $employee['id']);
        $this->assertNotNull($line);

        $gross = (float) $line['gross'];
        $paye = (float) $line['paye'];
        $nssf = (float) $line['nssf_employee'];
        $net = (float) $line['net'];

        $this->assertEqualsWithDelta($gross - $paye - $nssf, $net, 0.01);
        $this->assertEqualsWithDelta(1000000.0, $gross, 0.01);
        $this->assertEqualsWithDelta(50000.0, $nssf, 0.01); // 5% of basic
        $this->assertGreaterThan(0, $paye);
    }

    public function test_staff_with_hr_only_cannot_create_department(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['hr'],
        ]);
        $token = $staff->createToken('staff')->plainTextToken;

        $this->authJson('POST', '/api/v1/hr/departments', [
            'name' => 'Blocked Ops',
        ], $token)->assertStatus(403);
    }

    public function test_owner_without_hr_full_cannot_create_department(): void
    {
        $this->owner->update(['modules' => ['hr', 'settings']]);

        $this->authJson('POST', '/api/v1/hr/departments', [
            'name' => 'Owner Limited',
        ])->assertStatus(403);
    }

    public function test_staff_with_hr_only_can_clock_self_but_not_another(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['hr'],
            'name' => 'Self Clock',
        ]);
        $token = $staff->createToken('staff')->plainTextToken;

        $selfEmployee = $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-SELF',
            'first_name' => 'Self',
            'last_name' => 'Clock',
            'status' => 'active',
            'user_id' => $staff->id,
        ])->assertCreated()->json('data');

        $otherEmployee = $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-OTHER',
            'first_name' => 'Other',
            'last_name' => 'Worker',
            'status' => 'active',
        ])->assertCreated()->json('data');

        $this->authJson('POST', '/api/v1/hr/attendance/clock', [
            'employee_id' => $selfEmployee['id'],
            'type' => 'clock_in',
            'occurred_at' => '2026-07-10T08:00:00',
        ], $token)
            ->assertCreated()
            ->assertJsonPath('data.type', 'clock_in');

        $this->authJson('POST', '/api/v1/hr/attendance/clock', [
            'employee_id' => $otherEmployee['id'],
            'type' => 'clock_in',
            'occurred_at' => '2026-07-10T09:00:00',
        ], $token)->assertStatus(403);
    }

    public function test_performance_roster_and_employee_snapshot(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['hr', 'pipeline'],
            'name' => 'Perf Staff',
        ]);

        $employee = $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-PERF',
            'first_name' => 'Perf',
            'last_name' => 'Staff',
            'status' => 'active',
            'user_id' => $staff->id,
        ])->assertCreated()->json('data');

        $roster = $this->authJson('GET', '/api/v1/hr/talent/performance')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($roster);
        $row = collect($roster)->firstWhere('employee_id', $employee['id']);
        $this->assertNotNull($row);
        $this->assertSame('no_data', $row['verdict']);

        $detail = $this->authJson('GET', "/api/v1/hr/talent/performance/employees/{$employee['id']}")
            ->assertOk()
            ->json('data');

        $this->assertSame('linked', $detail['link_status']);
        $this->assertSame($staff->id, $detail['user_id']);
        $this->assertArrayHasKey('leads', $detail);
        $this->assertArrayHasKey('project_tasks', $detail);
        $this->assertArrayHasKey('goals', $detail);

        $byUser = $this->authJson('GET', "/api/v1/hr/talent/performance/by-user/{$staff->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame($employee['id'], $byUser['employee']['id']);

        $seeded = $this->authJson('POST', "/api/v1/hr/talent/performance/employees/{$employee['id']}/seed-review")
            ->assertCreated()
            ->json('data');

        $this->assertSame('draft', $seeded['review']['status']);
        $this->assertStringContainsString('Work performance', $seeded['review']['period_label']);
    }

    public function test_limited_hr_cannot_view_another_employee_performance(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['hr'],
        ]);
        $token = $staff->createToken('staff')->plainTextToken;

        $self = $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-LIM-SELF',
            'first_name' => 'Lim',
            'last_name' => 'Self',
            'status' => 'active',
            'user_id' => $staff->id,
        ])->assertCreated()->json('data');

        $other = $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => 'EMP-LIM-OTHER',
            'first_name' => 'Lim',
            'last_name' => 'Other',
            'status' => 'active',
        ])->assertCreated()->json('data');

        $this->authJson('GET', "/api/v1/hr/talent/performance/employees/{$self['id']}", [], $token)
            ->assertOk();

        $this->authJson('GET', "/api/v1/hr/talent/performance/employees/{$other['id']}", [], $token)
            ->assertStatus(403);

        $this->authJson('POST', "/api/v1/hr/talent/performance/employees/{$self['id']}/seed-review", [], $token)
            ->assertStatus(403);
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
        $posted = $this->authJson('POST', "/api/v1/hr/payroll/pay-runs/{$payRun['id']}/post")
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

        unset($posted);
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

