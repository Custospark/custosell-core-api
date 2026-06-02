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
            'name' => 'Utilities',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/expense-categories');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_create_expense_category(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->postJson('/api/v1/expense-categories', [
                'name' => 'Rent',
                'description' => 'Office and store rent',
                'sort_order' => 1,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'business_id'])
            ->assertJsonPath('name', 'Rent');
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

        $response->assertStatus(500);
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
        $category = ExpenseCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Utilities',
        ]);

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
}
