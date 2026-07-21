<?php

namespace Tests\Feature;

use App\Models\{Business, Product, Role, User};
use App\Services\ModuleAccessService;
use Database\Seeders\{PlanSeeder, SystemRoleSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->seed(SystemRoleSeeder::class);

        $owner = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create([
            'owner_id' => $owner->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->setUpSubscription();
    }

    public function test_module_access_not_role_permissions_governs_api_access(): void
    {
        $cashierRole = Role::query()->whereNull('business_id')->where('slug', 'cashier')->firstOrFail();

        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'role_id' => $cashierRole->id,
            'modules' => ['inventory'],
        ]);
        $token = $staff->createToken('staff')->plainTextToken;

        Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Shelf Item',
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/products')
            ->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Shelf Item');
    }

    public function test_can_perform_derives_from_modules_not_role_flags(): void
    {
        $moduleAccess = app(ModuleAccessService::class);

        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['dashboard', 'sales'],
        ]);

        $this->assertTrue($moduleAccess->canPerform($staff, 'reports.view'));
        $this->assertTrue($moduleAccess->canPerform($staff, 'sales.create'));
        $this->assertFalse($moduleAccess->canPerform($staff, 'inventory.view'));
    }

    public function test_sales_module_grants_expense_record_and_read(): void
    {
        $cashierRole = Role::query()->whereNull('business_id')->where('slug', 'cashier')->firstOrFail();

        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'role_id' => $cashierRole->id,
            'modules' => ['sales'],
        ]);
        $token = $staff->createToken('staff')->plainTextToken;

        $this->assertTrue(app(ModuleAccessService::class)->canPerform($staff, 'expenses.view'));
        $this->assertTrue(app(ModuleAccessService::class)->canPerform($staff, 'expenses.create'));

        $list = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/expenses');

        $list->assertStatus(200);
    }

    public function test_owner_personal_module_toggle_does_not_revoke_staff_grants(): void
    {
        $owner = User::query()->findOrFail($this->business->owner_id);
        $owner->forceFill([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['dashboard', 'sales', 'inventory', 'customers', 'settings'],
        ])->save();

        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['inventory', 'sales'],
        ]);

        $ownerToken = $owner->createToken('owner')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->putJson('/api/v1/auth/profile', [
                'modules' => ['dashboard', 'sales', 'settings'],
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            ['dashboard', 'sales', 'settings'],
            $owner->fresh()->modules,
        );
        $this->assertEqualsCanonicalizing(
            ['inventory', 'sales'],
            $staff->fresh()->modules,
            'Staff modules must survive owner personal Module Access saves.',
        );
    }
}
