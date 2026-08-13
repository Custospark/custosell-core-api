<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Onboarding dismissal must be persistent: once a user dismisses onboarding
 * ("No thanks"), neither the intent picker nor the tour may reappear on later
 * logins / onboarding state reads.
 */
class OnboardingDismissTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Business $business;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->token = $this->user->createToken('test')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->user->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->user->business_id = $this->business->id;

        $adminRole = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => ['subscriptions' => true],
        ]);
        $this->user->role_id = $adminRole->id;
        $this->user->save();
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_initial_onboarding_shows_intent_and_tour(): void
    {
        $this->getJson('/api/v1/auth/onboarding', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.needs_intent', true)
            ->assertJsonPath('data.needs_tour', true);
    }

    public function test_dismiss_onboarding_clears_intent_and_tour_persistently(): void
    {
        $this->patchJson('/api/v1/auth/onboarding', ['action' => 'dismiss_onboarding'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.needs_intent', false)
            ->assertJsonPath('data.needs_tour', false)
            ->assertJsonPath('data.intent_skipped_at', static fn ($v) => $v !== null)
            ->assertJsonPath('data.tour_skipped_at', static fn ($v) => $v !== null);

        // Onboarding state read again (next login) must not resurface intent or tour.
        $this->getJson('/api/v1/auth/onboarding', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.needs_intent', false)
            ->assertJsonPath('data.needs_tour', false);
    }

    public function test_skipped_intent_does_not_force_tour_after_login(): void
    {
        $this->patchJson('/api/v1/auth/onboarding', ['action' => 'skip_intent'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.needs_intent', false);

        // A skipped intent must not resurface the intent picker on later reads.
        $this->getJson('/api/v1/auth/onboarding', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.needs_intent', false);
    }
}