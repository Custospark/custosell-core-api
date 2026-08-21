<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Expense;
use App\Models\PersonalBudget;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetLineAndLinkTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Business $business;

    protected string $adminToken;

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

        $this->ensureSubscription($this->business->id);

        $role = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'is_system' => true,
            'permissions' => [
                'expenses.view' => true, 'expenses.create' => true,
                'reports.view' => true, 'settings.view' => true, 'settings.edit' => true,
            ],
        ]);
        $this->admin->role_id = $role->id;
        $this->admin->save();
    }

    protected function headers(): array
    {
        return ['Authorization' => "Bearer {$this->adminToken}"];
    }

    protected function makeBudget(): PersonalBudget
    {
        return PersonalBudget::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'name' => 'Groceries',
            'planned_amount' => 500000,
            'status' => 'active',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ]);
    }

    public function test_index_returns_budgets_array_shape(): void
    {
        $this->makeBudget();
        $this->makeBudget();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/budgets?status=active');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'budgets' => [
                '*' => ['id', 'name', 'planned_amount', 'status'],
            ],
            'total_planned',
        ]);
        $this->assertCount(2, $response->json('budgets'));
    }

    public function test_shopping_list_keeps_fractional_quantity_and_line_total(): void
    {
        $budget = $this->makeBudget();

        $response = $this->withHeaders($this->headers())
            ->putJson("/api/v1/budgets/{$budget->id}/lines", [
                'lines' => [
                    ['item_name' => 'Sugar', 'quantity' => 0.5, 'unit_price' => 4000],
                    ['item_name' => 'Milk', 'quantity' => 1, 'unit_price' => 5000],
                ],
            ]);

        $response->assertStatus(200);
        $lines = $response->json('data');

        $sugar = collect($lines)->firstWhere('item_name', 'Sugar');
        $this->assertEquals(0.5, (float) $sugar['quantity']);
        $this->assertEquals(2000, (float) $sugar['line_total']);

        $milk = collect($lines)->firstWhere('item_name', 'Milk');
        $this->assertEquals(1, (int) $milk['quantity']);
        $this->assertEquals(5000, (float) $milk['line_total']);
    }

    public function test_purchasing_fractional_line_converts_to_expense_with_budget_link(): void
    {
        $budget = $this->makeBudget();

        $lines = $this->withHeaders($this->headers())
            ->putJson("/api/v1/budgets/{$budget->id}/lines", [
                'lines' => [
                    ['item_name' => 'Sugar', 'quantity' => 0.5, 'unit_price' => 4000],
                ],
            ])->json('data');
        $lineId = $lines[0]['id'];

        $purchase = $this->withHeaders($this->headers())
            ->postJson("/api/v1/budgets/{$budget->id}/lines/{$lineId}/purchase", [
                'description' => 'Bought: Sugar',
            ]);

        $purchase->assertStatus(200);

        $this->assertDatabaseHas('expenses', [
            'business_id' => $this->business->id,
            'budget_id' => $budget->id,
            'amount' => 2000,
        ]);
        $this->assertDatabaseHas('budget_lines', [
            'id' => $lineId,
            'purchased' => true,
        ]);
    }

    public function test_expense_update_links_and_then_clears_budget(): void
    {
        $budget = $this->makeBudget();

        $expense = Expense::create([
            'business_id' => $this->business->id,
            'recorded_by' => $this->admin->id,
            'expense_category_id' => null,
            'amount' => 12000,
            'description' => 'Office supplies',
            'expense_date' => now()->toDateString(),
        ]);

        // Link to the budget on update.
        $linked = $this->withHeaders($this->headers())
            ->postJson("/api/v1/expenses/{$expense->id}", [
                '_method' => 'PUT',
                'amount' => 12000,
                'description' => 'Office supplies',
                'expense_date' => now()->toDateString(),
                'budget_id' => $budget->id,
            ]);
        $linked->assertStatus(200)
            ->assertJsonPath('data.budget_id', $budget->id);
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'budget_id' => $budget->id]);

        // Clear the link on a later update (empty budget_id).
        $cleared = $this->withHeaders($this->headers())
            ->postJson("/api/v1/expenses/{$expense->id}", [
                '_method' => 'PUT',
                'amount' => 12000,
                'description' => 'Office supplies',
                'expense_date' => now()->toDateString(),
                'budget_id' => '',
            ]);
        $cleared->assertStatus(200);
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'budget_id' => null]);
    }
}