<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Hr\HrAuditLog;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrModuleTest extends TestCase
{
    use RefreshDatabase;

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
            'modules' => ['hr', 'settings'],
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

        return $this->withHeader('Authorization', "Bearer {$token}")
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
}
