<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PersonalBudget;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomeSourceBudgetLinkTest extends TestCase
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

        $adminRole = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'is_system' => true,
            'permissions' => [
                'sales.create' => true, 'sales.view' => true,
                'expenses.view' => true, 'expenses.create' => true,
                'reports.view' => true, 'settings.view' => true, 'settings.edit' => true,
            ],
        ]);
        $this->admin->role_id = $adminRole->id;
        $this->admin->save();
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => "Bearer {$this->adminToken}"];
    }

    public function test_update_income_persists_and_returns_budget_id(): void
    {
        $budget = PersonalBudget::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'name' => 'Groceries',
            'planned_amount' => 500000,
            'status' => 'active',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ]);

        $store = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/income-sources', [
                'source_name' => 'Salary',
                'amount' => 200000,
                'income_date' => now()->toDateString(),
            ]);
        $store->assertStatus(201);
        $incomeId = $store->json('data.id');

        // The resource must expose budget_id so the edit form can pre-select it.
        $update = $this->withHeaders($this->authHeaders())
            ->putJson("/api/v1/income-sources/{$incomeId}", [
                'source_name' => 'Salary',
                'amount' => 250000,
                'income_date' => now()->toDateString(),
                'budget_id' => $budget->id,
            ]);
        $update->assertStatus(200)
            ->assertJsonPath('data.budget_id', $budget->id);

        // Persisted in the DB.
        $this->assertDatabaseHas('income_sources', [
            'id' => $incomeId,
            'budget_id' => $budget->id,
        ]);

        // The detail endpoint returns budget_id too (edit reload).
        $show = $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/income-sources/{$incomeId}");
        $show->assertStatus(200)
            ->assertJsonPath('data.budget_id', $budget->id);
    }
}
