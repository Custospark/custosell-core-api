<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Services\Assistant\Tools\AssistantToolExecutor;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function makeBusiness(User $owner, array $modules = ['dashboard', 'sales', 'inventory', 'settings']): Business
    {
        $business = Business::factory()->create([
            'owner_id' => $owner->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $owner->business_id = $business->id;
        $owner->modules = $modules;
        $owner->save();

        return $business;
    }

    public function test_unknown_tool_never_executes(): void
    {
        $this->seed(PlanSeeder::class);
        $owner = User::factory()->create(['is_active' => true]);
        $this->makeBusiness($owner);

        $result = app(AssistantToolExecutor::class)->execute($owner, 'drop_tables', []);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_staff_without_module_gets_denial_not_data(): void
    {
        $this->seed(PlanSeeder::class);
        $owner = User::factory()->create(['is_active' => true]);
        $business = $this->makeBusiness($owner);

        Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Secret Widget',
            'stock_quantity' => 0,
            'low_stock_threshold' => 5,
        ]);

        $staff = User::factory()->create(['is_active' => true, 'business_id' => $business->id, 'modules' => ['sales']]);

        $result = app(AssistantToolExecutor::class)->execute($staff, 'low_stock', []);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('inventory', strtolower($result['error']));
    }

    public function test_user_cannot_see_another_business_data(): void
    {
        $this->seed(PlanSeeder::class);
        $ownerA = User::factory()->create(['is_active' => true]);
        $businessA = $this->makeBusiness($ownerA, ['dashboard', 'sales', 'inventory', 'settings']);
        $ownerB = User::factory()->create(['is_active' => true]);
        $businessB = $this->makeBusiness($ownerB);

        Product::factory()->create([
            'business_id' => $businessB->id,
            'name' => 'Competitor Gizmo',
            'stock_quantity' => 0,
            'low_stock_threshold' => 5,
        ]);

        // Forged args cannot smuggle another business: tools take no business id.
        $result = app(AssistantToolExecutor::class)->execute(
            $ownerA,
            'low_stock',
            ['business_id' => $businessB->id, 'limit' => 50]
        );

        $names = array_column($result['products'] ?? [], 'name');
        $this->assertNotContains('Competitor Gizmo', $names);

        // Absurd limits are clamped server-side.
        $this->assertLessThanOrEqual(20, count($result['products'] ?? []));
    }

    public function test_fiscal_tool_requires_efris_grant(): void
    {
        $this->seed(PlanSeeder::class);
        $owner = User::factory()->create(['is_active' => true]);
        $this->makeBusiness($owner, ['dashboard', 'sales', 'settings']);

        $result = app(AssistantToolExecutor::class)->execute($owner, 'fiscal_status', []);

        $this->assertArrayHasKey('error', $result);
    }
}
