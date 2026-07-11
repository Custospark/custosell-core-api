<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ChartOfAccount;
use App\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsAccounting;
use Tests\TestCase;

class CompanyAssetsTest extends TestCase
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
            'modules' => ['hr', 'hr_full', 'settings', 'expenses'],
        ]);

        $this->business = Business::factory()->create([
            'owner_id' => $this->owner->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);

        $this->owner->update(['business_id' => $this->business->id]);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $this->seedAccountingForBusiness($this->business);
    }

    protected function authJson(string $method, string $uri, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->ownerToken)->json($method, $uri, $data);
    }

    protected function createEmployee(string $number = 'EMP-001', string $first = 'Jane', string $last = 'Doe'): array
    {
        $dept = $this->authJson('POST', '/api/v1/hr/departments', [
            'name' => 'Operations ' . $number,
        ])->assertCreated()->json('data');

        return $this->authJson('POST', '/api/v1/hr/employees', [
            'employee_number' => $number,
            'first_name' => $first,
            'last_name' => $last,
            'department_id' => $dept['id'],
            'employment_type' => 'full_time',
            'status' => 'active',
            'hire_date' => '2026-01-15',
        ])->assertCreated()->json('data');
    }

    protected function createCompanyAsset(array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'Dell Laptop',
            'cost' => 2500000,
            'salvage_value' => 200000,
            'useful_life_months' => 36,
            'purchase_date' => '2026-07-01',
            'category' => 'laptop',
            'condition' => 'good',
        ], $overrides);

        return $this->authJson('POST', '/api/v1/hr/company-assets', $payload)
            ->assertCreated()
            ->json('data');
    }

    public function test_create_asset_with_custody_fields(): void
    {
        $payload = $this->createCompanyAsset([
            'name' => 'Dell Laptop',
            'asset_tag' => 'AST-001',
            'serial_number' => 'SN-ABC-123',
            'category' => 'laptop',
            'location' => 'Head Office',
            'condition' => 'new',
            'notes' => 'IT equipment',
        ]);

        $this->assertSame('Dell Laptop', $payload['name']);
        $this->assertSame('AST-001', $payload['asset_tag']);
        $this->assertSame('SN-ABC-123', $payload['serial_number']);
        $this->assertSame('laptop', $payload['category']);
        $this->assertSame('Head Office', $payload['location']);
        $this->assertSame('new', $payload['condition']);

        $account = ChartOfAccount::where('business_id', $this->business->id)
            ->where('code', '1203')
            ->first();
        $this->assertNotNull($account);
        $this->assertSame($account->id, $payload['account_id']);

        $this->assertDatabaseHas('fixed_assets', [
            'business_id' => $this->business->id,
            'name' => 'Dell Laptop',
            'asset_tag' => 'AST-001',
            'category' => 'laptop',
            'account_id' => $account->id,
        ]);
    }

    public function test_assign_transfer_return_happy_path(): void
    {
        $employeeA = $this->createEmployee('EMP-A', 'Alice', 'A');
        $employeeB = $this->createEmployee('EMP-B', 'Bob', 'B');

        $asset = $this->createCompanyAsset([
            'name' => 'iPhone',
            'cost' => 1500000,
            'salvage_value' => 100000,
            'useful_life_months' => 24,
            'category' => 'phone',
            'asset_tag' => 'PHONE-1',
        ]);
        $assetId = $asset['id'];

        $assigned = $this->authJson('POST', "/api/v1/hr/company-assets/{$assetId}/assign", [
            'employee_id' => $employeeA['id'],
            'notes' => 'Initial issue',
        ])->assertOk()->json('data');

        $this->assertSame($employeeA['id'], $assigned['assigned_employee_id']);
        $this->assertNotNull($assigned['assigned_at']);
        $this->assertNull($assigned['returned_at']);

        $this->assertDatabaseHas('fixed_asset_assignments', [
            'asset_id' => $assetId,
            'action' => 'assign',
            'to_employee_id' => $employeeA['id'],
        ]);

        $transferred = $this->authJson('POST', "/api/v1/hr/company-assets/{$assetId}/transfer", [
            'employee_id' => $employeeB['id'],
            'notes' => 'Team move',
        ])->assertOk()->json('data');

        $this->assertSame($employeeB['id'], $transferred['assigned_employee_id']);

        $this->assertDatabaseHas('fixed_asset_assignments', [
            'asset_id' => $assetId,
            'action' => 'transfer',
            'from_employee_id' => $employeeA['id'],
            'to_employee_id' => $employeeB['id'],
        ]);

        $returned = $this->authJson('POST', "/api/v1/hr/company-assets/{$assetId}/return", [
            'notes' => 'Returned to IT',
        ])->assertOk()->json('data');

        $this->assertNull($returned['assigned_employee_id']);
        $this->assertNotNull($returned['returned_at']);

        $this->assertDatabaseHas('fixed_asset_assignments', [
            'asset_id' => $assetId,
            'action' => 'return',
            'from_employee_id' => $employeeB['id'],
        ]);

        $history = $this->authJson('GET', "/api/v1/hr/company-assets/{$assetId}/assignments")
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $history);
    }

    public function test_assign_when_already_assigned_returns_422(): void
    {
        $employeeA = $this->createEmployee('EMP-A2', 'Alice', 'Two');
        $employeeB = $this->createEmployee('EMP-B2', 'Bob', 'Two');

        $asset = $this->createCompanyAsset([
            'name' => 'Desk',
            'cost' => 500000,
            'salvage_value' => 50000,
            'useful_life_months' => 60,
            'category' => 'furniture',
            'asset_tag' => 'DESK-1',
        ]);

        $this->authJson('POST', "/api/v1/hr/company-assets/{$asset['id']}/assign", [
            'employee_id' => $employeeA['id'],
        ])->assertOk();

        $this->authJson('POST', "/api/v1/hr/company-assets/{$asset['id']}/assign", [
            'employee_id' => $employeeB['id'],
        ])->assertStatus(422);
    }

    public function test_return_when_unassigned_returns_422(): void
    {
        $asset = $this->createCompanyAsset([
            'name' => 'Chair',
            'cost' => 200000,
            'salvage_value' => 20000,
            'useful_life_months' => 48,
            'category' => 'furniture',
            'asset_tag' => 'CHAIR-1',
        ]);

        $this->authJson('POST', "/api/v1/hr/company-assets/{$asset['id']}/return", [
            'notes' => 'Nothing to return',
        ])->assertStatus(422);
    }

    public function test_expense_with_fixed_asset_id(): void
    {
        $asset = $this->createCompanyAsset([
            'name' => 'Server',
            'cost' => 8000000,
            'salvage_value' => 500000,
            'useful_life_months' => 48,
            'category' => 'other',
            'asset_tag' => 'SRV-1',
        ]);
        $assetId = $asset['id'];

        $category = ExpenseCategory::query()
            ->whereNull('business_id')
            ->where('slug', 'utilities')
            ->first();

        if (!$category) {
            $category = ExpenseCategory::create([
                'business_id' => null,
                'name' => 'Utilities',
                'slug' => 'utilities',
            ]);
        }

        $response = $this->authJson('POST', '/api/v1/expenses', [
            'expense_category_id' => $category->id,
            'amount' => 75000,
            'description' => 'Server cooling fan replacement',
            'expense_date' => now()->toDateTimeString(),
            'fixed_asset_id' => $assetId,
        ]);

        $response->assertCreated();
        $payload = $response->json('data') ?? $response->json();
        $this->assertSame($assetId, $payload['fixed_asset_id']);

        $this->assertDatabaseHas('expenses', [
            'business_id' => $this->business->id,
            'fixed_asset_id' => $assetId,
            'description' => 'Server cooling fan replacement',
        ]);

        $maintenance = $this->authJson('GET', "/api/v1/hr/company-assets/{$assetId}/maintenance-expenses")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $maintenance);
        $this->assertSame($assetId, $maintenance[0]['fixed_asset_id']);
    }
}
