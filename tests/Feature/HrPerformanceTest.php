<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsAccounting;
use Tests\TestCase;

class HrPerformanceTest extends TestCase
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
}
