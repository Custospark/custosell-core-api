<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformUserPrivilegesTest extends TestCase
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

    private function targetUserWithBusiness(): array
    {
        $owner = User::factory()->create(['is_active' => true]);
        $business = Business::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);
        $owner->business_id = $business->id;
        $owner->save();

        return [$owner, $business];
    }

    private function planId(): int
    {
        return Plan::query()->where('slug', 'essential')->value('id');
    }

    public function test_requires_platform_manage_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/platform/users/1/privileges', ['account_type' => 'personal'])
            ->assertForbidden();
    }

    public function test_updates_account_type_and_normalizes_email(): void
    {
        [$owner, $business] = $this->targetUserWithBusiness();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/users/'.$owner->id.'/privileges', [
                'account_type' => 'storefront_buyer',
                'email' => '  NEW-OWNER@Example.com ',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Account privileges updated.')
            ->assertJsonPath('data.account_type', 'storefront_buyer')
            ->assertJsonPath('data.email', 'new-owner@example.com');

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'email' => 'new-owner@example.com', 'account_type' => 'storefront_buyer']);

        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'user.privileges.fields',
            'target_type' => 'user',
            'target_id' => $owner->id,
        ]);
    }

    public function test_rejects_email_already_in_use_case_insensitive(): void
    {
        [$owner, $business] = $this->targetUserWithBusiness();
        $originalEmail = $owner->email;
        User::factory()->create(['email' => 'Taken@Example.com']);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/v1/platform/users/'.$owner->id.'/privileges', [
                'email' => 'taken@example.com',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'That email is already in use.');

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'email' => $originalEmail]);
    }

    public function test_sets_password_as_plaintext_hash(): void
    {
        [$owner, $business] = $this->targetUserWithBusiness();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/v1/platform/users/'.$owner->id.'/privileges', [
                'password' => 'brand-new-pass123',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('brand-new-pass123', $owner->fresh()->password));
        $this->assertFalse(Hash::check('password', $owner->fresh()->password));
    }

    public function test_creates_and_activates_subscription_when_none_exists(): void
    {
        [$owner, $business] = $this->targetUserWithBusiness();
        $this->assertNull($business->subscription()->first());

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/v1/platform/users/'.$owner->id.'/privileges', [
                'plan_id' => $this->planId(),
                'billing_cycle' => 'yearly',
                'subscription_status' => 'active',
            ])
            ->assertOk();

        $subscription = $business->subscription()->first();
        $this->assertNotNull($subscription);
        $this->assertEquals('active', $subscription->status->value);
        $this->assertEquals('yearly', $subscription->billing_cycle);
        $this->assertNotNull($subscription->next_billing_date);
    }

    public function test_requires_plan_when_no_subscription_exists(): void
    {
        [$owner, $business] = $this->targetUserWithBusiness();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/v1/platform/users/'.$owner->id.'/privileges', [
                'subscription_status' => 'active',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No subscription exists. Select a plan to create one.');
    }

    public function test_rejects_when_user_has_no_business(): void
    {
        $target = User::factory()->create(['business_id' => null]);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/v1/platform/users/'.$target->id.'/privileges', [
                'plan_id' => $this->planId(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This account has no linked business.');
    }

    public function test_updates_existing_subscription_fields_and_audits(): void
    {
        [$owner, $business] = $this->targetUserWithBusiness();
        $admin = $this->admin();
        $subscription = Subscription::create([
            'business_id' => $business->id,
            'plan_id' => $this->planId(),
            'price_monthly_usd' => 20,
            'price_yearly_usd' => 200,
            'onboarding_fee_usd' => 40,
            'billing_cycle' => 'monthly',
            'status' => 'active',
            'starts_at' => now(),
            'next_billing_date' => now()->addMonth(),
            'onboarding_fee_paid' => false,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/users/'.$owner->id.'/privileges', [
                'onboarding_fee_paid' => true,
                'subscription_status' => 'suspended',
                'next_billing_date' => '2030-01-01',
            ])
            ->assertOk();

        $fresh = $subscription->fresh();
        $this->assertTrue((bool) $fresh->onboarding_fee_paid);
        $this->assertEquals('suspended', $fresh->status->value);
        $this->assertEquals('2030-01-01', $fresh->next_billing_date->toDateString());

        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'user.privileges.subscription',
            'target_type' => 'subscription',
            'target_id' => $subscription->id,
        ]);
    }

    public function test_rejects_invalid_subscription_status(): void
    {
        [$owner, $business] = $this->targetUserWithBusiness();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/v1/platform/users/'.$owner->id.'/privileges', [
                'subscription_status' => 'bogus',
            ])
            ->assertUnprocessable();
    }

    public function test_bulk_update_processes_users_and_reports_errors(): void
    {
        [$a, $businessA] = $this->targetUserWithBusiness();
        [$b, $businessB] = $this->targetUserWithBusiness();
        $noBusiness = User::factory()->create(['business_id' => null]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/users/bulk-privileges', [
                'ids' => [$a->id, $b->id, $noBusiness->id],
                'account_type' => 'personal',
                'subscription_status' => 'active',
                'plan_id' => $this->planId(),
            ])
            ->assertOk()
            ->assertJsonPath('processed', 2)
            ->assertJsonCount(1, 'errors')
            ->assertJsonPath('variant', 'warning');

        $this->assertDatabaseHas('users', ['id' => $a->id, 'account_type' => 'personal']);
        $this->assertDatabaseHas('users', ['id' => $b->id, 'account_type' => 'personal']);
        $this->assertDatabaseHas('users', ['id' => $noBusiness->id, 'account_type' => 'personal']);
        $this->assertNotNull($businessA->subscription()->first());
        $this->assertNotNull($businessB->subscription()->first());
    }

    public function test_bulk_update_all_success_returns_success_variant(): void
    {
        [$a, $businessA] = $this->targetUserWithBusiness();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/users/bulk-privileges', [
                'ids' => [$a->id],
                'account_type' => 'storefront_buyer',
            ])
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('variant', 'success')
            ->assertJsonCount(0, 'errors');
    }

    public function test_index_paginates_and_filters_server_side(): void
    {
        $planId = $this->planId();

        for ($i = 0; $i < 25; $i++) {
            $owner = User::factory()->create(['is_active' => true]);
            $business = Business::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);
            $owner->business_id = $business->id;
            $owner->save();
            Subscription::create([
                'business_id' => $business->id,
                'plan_id' => $planId,
                'price_monthly_usd' => 20,
                'price_yearly_usd' => 200,
                'onboarding_fee_usd' => 40,
                'billing_cycle' => 'monthly',
                'status' => 'active',
                'starts_at' => now(),
                'next_billing_date' => now()->addMonth(),
                'onboarding_fee_paid' => false,
            ]);
        }

        $named = User::where('email', 'like', '%.example%')->first() ?? User::query()->whereNotNull('business_id')->first();
        $targetName = $named->name;

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/platform/users?per_page=15')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'email', 'account_type', 'is_active', 'subscription']],
                'current_page', 'last_page', 'per_page', 'total',
            ])
            ->assertJsonPath('per_page', 15)
            ->assertJsonPath('total', 25)
            ->assertJsonPath('current_page', 1);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/platform/users?per_page=15&page=2')
            ->assertOk()
            ->assertJsonPath('current_page', 2);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/platform/users?search='.urlencode($targetName))
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_businesses_index_paginates_and_filters_server_side(): void
    {
        $planId = $this->planId();
        $businesses = [];

        for ($i = 0; $i < 20; $i++) {
            $owner = User::factory()->create(['is_active' => true]);
            $business = Business::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);
            $owner->business_id = $business->id;
            $owner->save();
            Subscription::create([
                'business_id' => $business->id,
                'plan_id' => $planId,
                'price_monthly_usd' => 20,
                'price_yearly_usd' => 200,
                'onboarding_fee_usd' => 40,
                'billing_cycle' => 'monthly',
                'status' => 'active',
                'starts_at' => now(),
                'next_billing_date' => now()->addMonth(),
                'onboarding_fee_paid' => false,
            ]);
            $businesses[] = $business;
        }

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/platform/businesses?per_page=15')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'status', 'subscription_status', 'plan_name', 'owner_name', 'owner_email']],
                'current_page', 'last_page', 'per_page', 'total',
            ])
            ->assertJsonPath('per_page', 15)
            ->assertJsonPath('total', 20)
            ->assertJsonPath('current_page', 1);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/platform/businesses?per_page=15&page=2')
            ->assertOk()
            ->assertJsonPath('current_page', 2);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/platform/businesses?search='.urlencode($businesses[0]->name))
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_businesses_index_filters_by_subscription_status(): void
    {
        $planId = $this->planId();

        $suspended = $this->targetUserWithBusiness();
        Subscription::create([
            'business_id' => $suspended[1]->id,
            'plan_id' => $planId,
            'price_monthly_usd' => 20,
            'price_yearly_usd' => 200,
            'onboarding_fee_usd' => 40,
            'billing_cycle' => 'monthly',
            'status' => 'suspended',
            'starts_at' => now(),
            'next_billing_date' => now()->addMonth(),
            'onboarding_fee_paid' => false,
        ]);

        $noSub = $this->targetUserWithBusiness();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/platform/businesses?subscription_status=suspended')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $suspended[1]->id);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/platform/businesses?subscription_status=none')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $noSub[1]->id);
    }
}
