<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Account type and plan pairing guards for platform privilege grants.
 * Keeps the UI rule mirrored on the API: business plans for business accounts,
 * personal plans for personal accounts, and no subscription at all for
 * storefront buyer accounts.
 */
class PlatformPrivilegeAccountTypeTest extends TestCase
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

    private function businessPlanId(): int
    {
        return Plan::query()->where('slug', 'essential')->value('id');
    }

    private function personalPlanId(): int
    {
        return Plan::query()->where('slug', 'personal')->value('id');
    }

    public function test_promotes_storefront_buyer_to_business_account_when_assigning_plan(): void
    {
        // A shopping-only account has no workspace. Once a business plan is
        // granted, it must become a business account or the global search top
        // bar and the whole workspace stay hidden (FE gates on account_type).
        $target = User::factory()->create(['business_id' => null, 'account_type' => 'storefront_buyer']);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/v1/platform/users/'.$target->id.'/privileges', [
                'plan_id' => $this->businessPlanId(),
            ])
            ->assertOk();

        $fresh = $target->fresh();
        $this->assertSame('business', $fresh->account_type);
        $this->assertNotNull($fresh->business_id);
        $this->assertContains('estimates_full', $fresh->modules);
        $this->assertContains('hr_full', $fresh->modules);
    }

    public function test_respects_account_type_passed_from_ui_when_creating_business(): void
    {
        $target = User::factory()->create(['business_id' => null, 'account_type' => 'personal']);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/v1/platform/users/'.$target->id.'/privileges', [
                'account_type' => 'personal',
                'plan_id' => $this->personalPlanId(),
            ])
            ->assertOk();

        $fresh = $target->fresh();
        // UI explicitly chose personal — honor it, don't force business.
        $this->assertSame('personal', $fresh->account_type);
        $this->assertNotNull($fresh->business_id);
    }

    public function test_rejects_storefront_buyer_with_subscription_changes(): void
    {
        $target = User::factory()->create(['business_id' => null, 'account_type' => 'storefront_buyer']);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/v1/platform/users/'.$target->id.'/privileges', [
                'account_type' => 'storefront_buyer',
                'plan_id' => $this->businessPlanId(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Storefront buyer accounts cannot have a subscription.');
    }

    public function test_rejects_plan_mismatched_to_account_type(): void
    {
        $target = User::factory()->create(['business_id' => null, 'account_type' => 'personal']);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/v1/platform/users/'.$target->id.'/privileges', [
                'account_type' => 'personal',
                'plan_id' => $this->businessPlanId(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Selected plan is for business accounts, not personal.');
    }

    public function test_rejects_personal_plan_for_business_account_type(): void
    {
        $target = User::factory()->create(['business_id' => null, 'account_type' => 'storefront_buyer']);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/v1/platform/users/'.$target->id.'/privileges', [
                'account_type' => 'business',
                'plan_id' => $this->personalPlanId(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Selected plan is for personal accounts, not business.');
    }
}
