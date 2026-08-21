<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Expense;
use App\Models\IncomeSource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RecurringTransactionsJobTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
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

    public function test_recurring_expense_fires_occurrence_and_advances_next_due(): void
    {
        $template = Expense::create([
            'business_id' => $this->business->id,
            'recorded_by' => $this->admin->id,
            'amount' => 120000,
            'description' => 'Monthly rent',
            'expense_date' => now()->subMonth()->toDateString(),
            'is_recurring' => true,
            'recurrence_interval' => 'monthly',
            'next_due_date' => now()->toDateString(),
        ]);

        Artisan::call('expenses:process-recurring');

        // A new occurrence was created for the due date; template advanced by a month.
        $this->assertSame(1, Expense::where('description', 'Monthly rent')->count() - 1);
        $occurrence = Expense::where('description', 'Monthly rent')
            ->where('is_recurring', false)
            ->first();
        $this->assertNotNull($occurrence);
        $this->assertEquals(now()->toDateString(), $occurrence->expense_date->toDateString());
        $this->assertEquals(now()->addMonth()->toDateString(), $template->fresh()->next_due_date->toDateString());
        $this->assertTrue((bool) $template->fresh()->is_recurring);
    }

    public function test_recurring_income_fires_occurrence_and_advances_next_due(): void
    {
        $template = IncomeSource::create([
            'business_id' => $this->business->id,
            'user_id' => $this->admin->id,
            'amount' => 300000,
            'source_name' => 'Salary',
            'income_date' => now()->subMonth()->toDateString(),
            'is_recurring' => true,
            'recurrence_interval' => 'monthly',
            'next_due_date' => now()->toDateString(),
        ]);

        Artisan::call('income:process-recurring');

        $occurrence = IncomeSource::where('source_name', 'Salary')
            ->where('is_recurring', false)
            ->first();
        $this->assertNotNull($occurrence);
        $this->assertEquals(now()->toDateString(), $occurrence->income_date->toDateString());
        $this->assertEquals(now()->addMonth()->toDateString(), $template->fresh()->next_due_date->toDateString());
    }

    public function test_recurring_expense_stops_when_past_end_date(): void
    {
        $template = Expense::create([
            'business_id' => $this->business->id,
            'recorded_by' => $this->admin->id,
            'amount' => 50000,
            'description' => 'Temporary subscription',
            'expense_date' => now()->subMonth()->toDateString(),
            'is_recurring' => true,
            'recurrence_interval' => 'monthly',
            'recurrence_end_date' => now()->toDateString(),
            'next_due_date' => now()->toDateString(),
        ]);

        Artisan::call('expenses:process-recurring');

        // Series ends: no new occurrence created; the template itself stops recurring.
        $this->assertSame(0, Expense::where('description', 'Temporary subscription')
            ->where('id', '!=', $template->id)
            ->count());
        $fresh = $template->fresh();
        $this->assertFalse((bool) $fresh->is_recurring);
        $this->assertNull($fresh->next_due_date);
    }
}