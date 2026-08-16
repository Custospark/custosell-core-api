<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\LinkedAccount;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LinkedAccountTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Business $ownerBusiness;

    protected User $other;

    protected Business $otherBusiness;

    protected string $ownerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->owner = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
            'account_type' => 'business',
        ]);
        $this->ownerToken = $this->owner->createToken('test')->plainTextToken;

        $this->ownerBusiness = Business::factory()->create([
            'owner_id' => $this->owner->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->owner->business_id = $this->ownerBusiness->id;
        $this->owner->save();
        $this->ensureSubscription($this->ownerBusiness->id);

        Role::create([
            'business_id' => $this->ownerBusiness->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'is_system' => true,
            'permissions' => ['settings.view' => true],
        ]);

        $this->other = User::factory()->create([
            'email' => 'other@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
            'account_type' => 'business',
        ]);
        $this->otherBusiness = Business::factory()->create([
            'owner_id' => $this->other->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->other->business_id = $this->otherBusiness->id;
        $this->other->save();
        $this->ensureSubscription($this->otherBusiness->id);
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => "Bearer {$this->ownerToken}"];
    }

    public function test_first_link_becomes_primary(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts', [
                'email' => 'other@example.com',
                'password' => 'password123',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.relation', 'primary')
            ->assertJsonPath('data.linked_account.email', 'other@example.com');

        $this->assertDatabaseHas('linked_accounts', [
            'owner_user_id' => $this->owner->id,
            'linked_user_id' => $this->other->id,
            'relation' => 'primary',
        ]);
    }

    public function test_second_link_becomes_secondary(): void
    {
        $third = User::factory()->create([
            'email' => 'third@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts', ['email' => 'other@example.com', 'password' => 'password123'])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts', ['email' => 'third@example.com', 'password' => 'password123'])
            ->assertStatus(201);

        $response->assertJsonPath('data.relation', 'secondary');
    }

    public function test_link_rejects_wrong_credentials(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts', [
                'email' => 'other@example.com',
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('linked_accounts', 0);
    }

    public function test_link_rejects_own_account(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts', [
                'email' => 'owner@example.com',
                'password' => 'password123',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('linked_accounts', 0);
    }

    public function test_link_is_idempotent_for_existing_pair(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts', ['email' => 'other@example.com', 'password' => 'password123'])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts', ['email' => 'other@example.com', 'password' => 'password123'])
            ->assertStatus(201);

        $response->assertJsonPath('data.relation', 'primary');
        $this->assertDatabaseCount('linked_accounts', 1);
    }

    public function test_list_is_scoped_to_owner_and_primary_first(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts', ['email' => 'other@example.com', 'password' => 'password123'])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/linked-accounts');

        $response->assertStatus(200)
            ->assertJsonPath('data.primary.email', 'other@example.com')
            ->assertJsonCount(1, 'data.accounts');
    }

    public function test_switch_returns_full_auth_payload(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts', ['email' => 'other@example.com', 'password' => 'password123'])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts/' . $this->other->id . '/switch');

        $response->assertStatus(200)
            ->assertJsonPath('data.user.id', $this->other->id)
            ->assertJsonPath('data.user.email', 'other@example.com')
            ->assertJsonPath('data.user.business_id', $this->otherBusiness->id)
            ->assertJsonPath('data.user.business.name', $this->otherBusiness->name)
            ->assertJsonStructure([
                'data' => [
                    'user' => [
                        'id',
                        'email',
                        'business_id',
                        'business' => ['name', 'subscription' => ['status', 'plan_name']],
                    ],
                ],
            ]);
    }

    public function test_switch_rejects_unlinked_account(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts/' . $this->other->id . '/switch');

        $response->assertStatus(422);
    }

    public function test_set_primary_promotes_secondary_and_demotes_primary(): void
    {
        $third = User::factory()->create([
            'email' => 'third@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts', ['email' => 'other@example.com', 'password' => 'password123']);
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts', ['email' => 'third@example.com', 'password' => 'password123']);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts/' . $third->id . '/set-primary');

        $response->assertStatus(200)
            ->assertJsonPath('data.primary.email', 'third@example.com');

        $this->assertDatabaseHas('linked_accounts', [
            'owner_user_id' => $this->owner->id,
            'linked_user_id' => $third->id,
            'relation' => 'primary',
        ]);
        $this->assertDatabaseHas('linked_accounts', [
            'owner_user_id' => $this->owner->id,
            'linked_user_id' => $this->other->id,
            'relation' => 'secondary',
        ]);
    }

    public function test_primary_cannot_be_unlinked(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts', ['email' => 'other@example.com', 'password' => 'password123']);

        $response = $this->withHeaders($this->authHeaders())
            ->deleteJson('/api/v1/linked-accounts/' . $this->other->id);

        $response->assertStatus(422);
        $this->assertDatabaseCount('linked_accounts', 1);
    }

    public function test_secondary_can_be_unlinked(): void
    {
        $third = User::factory()->create([
            'email' => 'third@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts', ['email' => 'other@example.com', 'password' => 'password123']);
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts', ['email' => 'third@example.com', 'password' => 'password123']);

        $response = $this->withHeaders($this->authHeaders())
            ->deleteJson('/api/v1/linked-accounts/' . $third->id);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('linked_accounts', ['linked_user_id' => $third->id]);
        $this->assertDatabaseHas('linked_accounts', ['linked_user_id' => $this->other->id, 'relation' => 'primary']);
    }

    public function test_other_user_cannot_switch_someone_elses_link(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/linked-accounts', ['email' => 'other@example.com', 'password' => 'password123']);

        // "other" tries to switch using the owner's link row.
        $otherToken = $this->other->createToken('test')->plainTextToken;
        $response = $this->withHeaders(['Authorization' => "Bearer {$otherToken}"])
            ->postJson('/api/v1/linked-accounts/' . $this->owner->id . '/switch');

        $response->assertStatus(422);
    }
}
