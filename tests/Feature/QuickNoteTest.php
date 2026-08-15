<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\QuickNote;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickNoteTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $staff;
    protected User $personal;
    protected User $buyer;
    protected Business $business;
    protected string $ownerToken;
    protected string $staffToken;
    protected string $personalToken;
    protected string $buyerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->owner = User::factory()->create(['is_active' => true, 'account_type' => 'business']);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->owner->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->owner->business_id = $this->business->id;
        $this->owner->save();

        $ownerRole = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Owner',
            'slug' => 'owner',
            'permissions' => [],
        ]);
        $this->owner->role_id = $ownerRole->id;
        $this->owner->save();

        $this->staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
        ]);
        $staffRole = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Staff',
            'slug' => 'staff',
            'permissions' => [],
        ]);
        $this->staff->role_id = $staffRole->id;
        $this->staff->save();
        $this->staffToken = $this->staff->createToken('staff')->plainTextToken;

        $this->personal = User::factory()->create(['is_active' => true, 'account_type' => 'personal']);
        $this->personal->business_id = $this->business->id;
        $this->personal->save();
        $this->personalToken = $this->personal->createToken('personal')->plainTextToken;

        $this->buyer = User::factory()->create(['is_active' => true, 'account_type' => 'storefront_buyer']);
        $this->buyerToken = $this->buyer->createToken('buyer')->plainTextToken;

        $this->setUpSubscription();
    }

    public function test_business_owner_can_create_note(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->postJson('/api/v1/quick-notes', [
                'title' => 'Restock milk',
                'body' => 'Order 20 crates',
                'color' => 'blue',
                'tag' => 'ops',
                'is_shared' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Restock milk')
            ->assertJsonPath('data.is_shared', true)
            ->assertJsonPath('data.user_id', $this->owner->id);

        $this->assertDatabaseHas('quick_notes', [
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'title' => 'Restock milk',
            'client_uuid' => $response->json('data.client_uuid'),
        ]);
    }

    public function test_staff_can_share_note(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->postJson('/api/v1/quick-notes', [
                'title' => 'Team reminder',
                'is_shared' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.is_shared', true);
    }

    public function test_personal_account_cannot_share_note(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->personalToken")
            ->postJson('/api/v1/quick-notes', [
                'title' => 'Private',
                'is_shared' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.is_shared', false);
    }

    public function test_storefront_buyer_cannot_use_feature(): void
    {
        $this->withHeader('Authorization', "Bearer $this->buyerToken")
            ->getJson('/api/v1/quick-notes')
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer $this->buyerToken")
            ->postJson('/api/v1/quick-notes', ['title' => 'Nope'])
            ->assertStatus(403);
    }

    public function test_user_sees_own_and_shared_notes_only(): void
    {
        QuickNote::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'title' => 'Own private',
            'is_shared' => false,
        ]);
        QuickNote::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'title' => 'Shared by owner',
            'is_shared' => true,
        ]);
        QuickNote::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'title' => 'Staff private',
            'is_shared' => false,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->getJson('/api/v1/quick-notes');

        $titles = collect($response->json('data'))->pluck('title')->all();

        $response->assertStatus(200);
        $this->assertContains('Shared by owner', $titles);
        $this->assertContains('Staff private', $titles);
        $this->assertNotContains('Own private', $titles);
    }

    public function test_user_cannot_update_or_delete_others_private_note(): void
    {
        $note = QuickNote::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'title' => 'Owned by owner',
            'is_shared' => false,
        ]);

        $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->putJson("/api/v1/quick-notes/{$note->id}", ['title' => 'Hacked'])
            ->assertStatus(404);

        $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->deleteJson("/api/v1/quick-notes/{$note->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('quick_notes', ['id' => $note->id]);
    }

    public function test_owner_can_update_and_soft_delete_note(): void
    {
        $note = QuickNote::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'title' => 'Todo',
            'is_shared' => false,
        ]);

        $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->patchJson("/api/v1/quick-notes/{$note->id}", ['title' => 'Todo updated', 'tag' => 'home'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Todo updated')
            ->assertJsonPath('data.tag', 'home');

        $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->deleteJson("/api/v1/quick-notes/{$note->id}")
            ->assertStatus(204);

        $this->assertSoftDeleted('quick_notes', ['id' => $note->id]);
    }

    public function test_create_is_idempotent_by_client_uuid(): void
    {
        $payload = [
            'title' => 'One note',
            'body' => 'Created twice',
            'client_uuid' => '11111111-1111-1111-1111-111111111111',
        ];

        $first = $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->postJson('/api/v1/quick-notes', $payload);

        $second = $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->postJson('/api/v1/quick-notes', $payload);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, QuickNote::where('client_uuid', '11111111-1111-1111-1111-111111111111')->count());
    }

    public function test_sync_push_quick_note_is_idempotent_by_client_uuid(): void
    {
        $payload = [
            'quick_notes' => [
                [
                    'client_uuid' => '22222222-2222-2222-2222-222222222222',
                    'title' => 'From offline',
                    'body' => 'Created on a phone',
                    'is_shared' => false,
                ],
            ],
        ];

        $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->postJson('/api/v1/sync/push', $payload)
            ->assertStatus(200)
            ->assertJsonPath('imported.quick_notes', 1);

        $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->postJson('/api/v1/sync/push', $payload)
            ->assertStatus(200)
            ->assertJsonPath('imported.quick_notes', 1);

        $this->assertSame(1, QuickNote::where('client_uuid', '22222222-2222-2222-2222-222222222222')->count());
    }

    public function test_sync_pull_returns_quick_notes_with_trashed(): void
    {
        $note = QuickNote::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'title' => 'To be deleted',
            'is_shared' => false,
        ]);
        $note->delete();

        $response = $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->getJson('/api/v1/sync/pull');

        $response->assertStatus(200);
        $notes = collect($response->json('quick_notes'));
        $this->assertCount(1, $notes);
        $this->assertNotNull($notes->first()['deleted_at']);
    }
}
