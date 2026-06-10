<?php

namespace Tests\Feature;

use App\Models\{Business, Category, Customer, Plan, Product, Role, Sale, SaleItem, Shift, StockMovement, Subscription, ExpenseCategory, Expense, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $staff;
    protected Business $business;
    protected string $adminToken;
    protected string $staffToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->adminToken = $this->admin->createToken('admin')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->admin->business_id = $this->business->id;
        $this->admin->save();

        $adminRole = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => [
                'sales.create' => true, 'sales.view' => true, 'sales.refund' => true,
                'inventory.view' => true, 'inventory.create' => true,
                'customers.view' => true, 'customers.create' => true,
                'expenses.view' => true, 'expenses.create' => true,
                'users.view' => true, 'users.create' => true,
                'reports.view' => true, 'settings.view' => true, 'settings.edit' => true,
            ],
        ]);
        $this->admin->role_id = $adminRole->id;
        $this->admin->save();

        $this->staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['sales'],
        ]);
        $staffRole = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Staff',
            'slug' => 'staff',
            'permissions' => [
                'sales.create' => true, 'sales.view' => true, 'sales.refund' => false,
                'inventory.view' => true, 'inventory.create' => false,
                'customers.view' => true, 'customers.create' => true,
                'expenses.view' => false, 'expenses.create' => false,
                'users.view' => false, 'users.create' => false,
                'reports.view' => false, 'settings.view' => false, 'settings.edit' => false,
            ],
        ]);
        $this->staff->role_id = $staffRole->id;
        $this->staff->save();
        $this->staffToken = $this->staff->createToken('staff')->plainTextToken;
    }

    public function test_list_expense_categories(): void
    {
        ExpenseCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Custom Utilities',
            'slug' => 'custom-utilities',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/expense-categories');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);

        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Rent', $names);
        $this->assertContains('Custom Utilities', $names);
    }

    public function test_create_expense_category(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/expense-categories', [
                'name' => 'Store Rent',
                'description' => 'Office and store rent',
                'sort_order' => 1,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'business_id', 'is_system'])
            ->assertJsonPath('name', 'Store Rent')
            ->assertJsonPath('is_system', false);
    }

    public function test_cannot_create_category_with_system_name(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/expense-categories', [
                'name' => 'Rent',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_duplicate_category_name_returns_422(): void
    {
        $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/expense-categories', [
                'name' => 'Salaries',
            ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/expense-categories', [
                'name' => 'Salaries',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_list_expenses(): void
    {
        Expense::create([
            'business_id' => $this->business->id,
            'recorded_by' => $this->admin->id,
            'amount' => 50000,
            'description' => 'Office supplies',
            'expense_date' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/expenses');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_create_expense(): void
    {
        $category = ExpenseCategory::query()
            ->whereNull('business_id')
            ->where('slug', 'utilities')
            ->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/expenses', [
                'expense_category_id' => $category->id,
                'amount' => 150000,
                'description' => 'Electricity bill',
                'expense_date' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'amount', 'description', 'expense_category_id'])
            ->assertJsonPath('amount', '150000.00');
    }

    public function test_create_shift_expense_and_filter_by_shift(): void
    {
        $shift = Shift::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'clock_in' => now(),
            'status' => 'active',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->postJson('/api/v1/expenses', [
                'shift_id' => $shift->id,
                'amount' => 25000,
                'description' => 'Delivery fuel',
                'expense_date' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('shift_id', $shift->id)
            ->assertJsonPath('amount', '25000.00');

        $list = $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->getJson("/api/v1/expenses/by-shift/{$shift->id}");

        $list->assertStatus(200);
        $this->assertEquals(['Delivery fuel'], collect($list->json('data'))->pluck('description')->all());
    }

    public function test_create_expense_rejects_other_business_shift(): void
    {
        $otherBusiness = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $otherShift = Shift::create([
            'business_id' => $otherBusiness->id,
            'user_id' => $this->admin->id,
            'clock_in' => now(),
            'status' => 'active',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->postJson('/api/v1/expenses', [
                'shift_id' => $otherShift->id,
                'amount' => 25000,
                'description' => 'Invalid shift',
                'expense_date' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['shift_id']);
    }

    public function test_negative_expense_amount_returns_422(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/expenses', [
                'amount' => -5000,
                'description' => 'Negative amount',
                'expense_date' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_update_expense(): void
    {
        $expense = Expense::create([
            'business_id' => $this->business->id,
            'recorded_by' => $this->admin->id,
            'amount' => 100000,
            'description' => 'Original description',
            'expense_date' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->putJson("/api/v1/expenses/{$expense->id}", [
                'amount' => 120000,
                'description' => 'Updated description',
                'expense_date' => $expense->expense_date->toDateTimeString(),
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.amount', '120000.00')
            ->assertJsonPath('data.description', 'Updated description');
    }

    public function test_delete_expense(): void
    {
        $expense = Expense::create([
            'business_id' => $this->business->id,
            'recorded_by' => $this->admin->id,
            'amount' => 50000,
            'description' => 'To be deleted',
            'expense_date' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->deleteJson("/api/v1/expenses/{$expense->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
    }

    public function test_expenses_scoped_per_business(): void
    {
        Expense::create([
            'business_id' => $this->business->id,
            'recorded_by' => $this->admin->id,
            'amount' => 50000,
            'description' => 'Our expense',
            'expense_date' => now(),
        ]);

        $otherBusiness = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        Expense::create([
            'business_id' => $otherBusiness->id,
            'recorded_by' => $this->admin->id,
            'amount' => 99999,
            'description' => 'Other business expense',
            'expense_date' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/expenses');

        $response->assertStatus(200);
        $descriptions = collect($response->json('data'))->pluck('description')->toArray();
        $this->assertContains('Our expense', $descriptions);
        $this->assertNotContains('Other business expense', $descriptions);
    }

    public function test_sales_only_staff_can_record_and_read_shift_expenses(): void
    {
        $cashier = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['sales'],
            'role_id' => $this->staff->role_id,
        ]);
        $cashierToken = $cashier->createToken('cashier')->plainTextToken;

        $category = ExpenseCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Petty cash',
            'slug' => 'petty-cash',
        ]);

        $shift = Shift::create([
            'business_id' => $this->business->id,
            'user_id' => $cashier->id,
            'clock_in' => now(),
            'status' => 'active',
        ]);

        $this->withHeader('Authorization', "Bearer $cashierToken")
            ->getJson('/api/v1/expenses/summary')
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer $cashierToken")
            ->getJson('/api/v1/expense-categories')
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Petty cash']);

        $create = $this->withHeader('Authorization', "Bearer $cashierToken")
            ->postJson('/api/v1/expenses', [
                'expense_category_id' => $category->id,
                'description' => 'Lunch for team',
                'amount' => 15000,
                'shift_id' => $shift->id,
                'expense_date' => now()->toDateTimeString(),
            ]);

        $create->assertStatus(201)
            ->assertJsonPath('description', 'Lunch for team');

        $this->withHeader('Authorization', "Bearer $cashierToken")
            ->getJson("/api/v1/expenses/by-shift/{$shift->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.0.description', 'Lunch for team');
    }

    public function test_system_expense_categories_are_seeded(): void
    {
        $this->assertDatabaseHas('expense_categories', [
            'business_id' => null,
            'slug' => 'rent',
        ]);
    }

    public function test_cannot_update_system_expense_category(): void
    {
        $category = ExpenseCategory::query()->whereNull('business_id')->where('slug', 'rent')->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->putJson("/api/v1/expense-categories/{$category->id}", [
                'name' => 'Rent',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['expense_category']);
    }

    public function test_cannot_delete_system_expense_category(): void
    {
        $category = ExpenseCategory::query()->whereNull('business_id')->where('slug', 'miscellaneous')->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->deleteJson("/api/v1/expense-categories/{$category->id}");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['expense_category']);
    }

    public function test_list_includes_system_flag_on_categories(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/expense-categories');

        $response->assertStatus(200);
        $rent = collect($response->json('data'))->firstWhere('slug', 'rent');
        $this->assertNotNull($rent);
        $this->assertTrue($rent['is_system']);
    }
}
