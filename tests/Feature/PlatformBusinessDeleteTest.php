<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\AccountType;
use App\Models\Business;
use App\Models\ChartOfAccount;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformBusinessDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('platform-admin');

        return $admin;
    }

    private function businessWithAccounting(): array
    {
        $owner = User::factory()->create(['is_active' => true]);
        $business = Business::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);
        $owner->business_id = $business->id;
        $owner->save();

        AccountingPeriod::create([
            'business_id' => $business->id,
            'name' => '2026 FY',
            'start_date' => now()->startOfYear(),
            'end_date' => now()->endOfYear(),
            'is_closed' => false,
        ]);

        $assetType = AccountType::create([
            'name' => 'Asset',
            'normal_balance' => 'debit',
        ]);
        ChartOfAccount::create([
            'business_id' => $business->id,
            'code' => '1000',
            'name' => 'Cash',
            'type_id' => $assetType->id,
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);

        return [$owner, $business];
    }

    public function test_delete_purges_accounting_periods_and_child_rows_then_hard_deletes(): void
    {
        [$owner, $business] = $this->businessWithAccounting();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/platform/businesses/'.$business->id, [
                'reason' => 'Duplicate test business, removing.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Business deleted.');

        $this->assertDatabaseMissing('businesses', ['id' => $business->id]);
        $this->assertDatabaseMissing('accounting_periods', ['business_id' => $business->id]);
        $this->assertDatabaseMissing('chart_of_accounts', ['business_id' => $business->id]);

        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'business.deleted',
            'target_type' => 'business',
            'target_id' => $business->id,
        ]);
    }

    public function test_delete_without_accounting_still_succeeds(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $business = Business::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/platform/businesses/'.$business->id, [
                'reason' => 'Cleanup on request.',
            ])
            ->assertOk();

        $this->assertDatabaseMissing('businesses', ['id' => $business->id]);
    }

    public function test_delete_requires_reason(): void
    {
        [$owner, $business] = $this->businessWithAccounting();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/platform/businesses/'.$business->id)
            ->assertUnprocessable();

        $this->assertDatabaseHas('businesses', ['id' => $business->id]);
    }
}