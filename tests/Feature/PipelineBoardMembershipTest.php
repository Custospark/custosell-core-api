<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\PipelineBoard;
use App\Models\PipelineBoardMember;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineBoardMembershipTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->seed(SystemRoleSeeder::class);

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create([
            'owner_id' => $this->owner->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->owner->update(['business_id' => $this->business->id]);
        $this->ensureSubscription($this->business->id, Plan::where('slug', 'professional')->first()?->id);
        $this->token = $this->owner->createToken('owner')->plainTextToken;
    }

    protected function sharedBoard(): PipelineBoard
    {
        return PipelineBoard::create([
            'business_id' => $this->business->id,
            'created_by' => $this->owner->id,
            'name' => 'Collaborative board',
            'visibility' => 'shared',
            'workspace' => 'pipeline',
        ]);
    }

    protected function staff(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'business_id' => $this->business->id,
            'is_active' => true,
        ], $overrides));
    }

    protected function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_team_members_lists_active_staff_with_expected_contract(): void
    {
        $this->staff(['name' => 'Alice Ada', 'email' => 'alice@example.test']);
        $this->staff(['name' => 'Bob Builder', 'email' => 'bob@example.test']);
        $this->staff(['name' => 'Dormant Den', 'is_active' => false]);

        $names = $this->withHeaders($this->headers($this->token))
            ->getJson('/api/v1/pipeline/team-members?workspace=pipeline&scope=business')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['*' => ['id', 'name', 'email', 'avatar', 'modules']],
            ])
            ->collect('data')->pluck('name')->all();

        $this->assertContains('Alice Ada', $names);
        $this->assertContains('Bob Builder', $names);
        $this->assertNotContains('Dormant Den', $names);
    }

    public function test_team_members_workspace_scope_excludes_staff_without_pipeline(): void
    {
        $this->staff(['name' => 'No Pipe', 'modules' => ['sales']]);
        $this->staff(['name' => 'Has Pipe', 'modules' => ['pipeline']]);

        $names = $this->withHeaders($this->headers($this->token))
            ->getJson('/api/v1/pipeline/team-members?workspace=pipeline&scope=workspace')
            ->assertOk()
            ->collect('data')->pluck('name')->all();

        $this->assertContains('Has Pipe', $names);
        $this->assertNotContains('No Pipe', $names);
    }

    public function test_team_members_rejects_invalid_scope(): void
    {
        $this->withHeaders($this->headers($this->token))
            ->getJson('/api/v1/pipeline/team-members?scope=galaxy')
            ->assertStatus(422);
    }

    public function test_owner_can_add_members_with_send_notification_flag(): void
    {
        $board = $this->sharedBoard();
        $alice = $this->staff(['email' => 'alice@example.test', 'name' => 'Alice Ada']);

        $this->withHeaders($this->headers($this->token))
            ->patchJson("/api/v1/pipeline/boards/{$board->id}", [
                'members' => [
                    ['user_id' => $alice->id, 'role' => 'manager', 'send_notification' => true],
                ],
            ])
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'members' => [
                        '*' => ['id', 'user_id', 'role', 'user' => ['id', 'name', 'email', 'avatar']],
                    ],
                ],
            ])
            ->assertJsonPath('data.members.0.user_id', $alice->id)
            ->assertJsonPath('data.members.0.role', 'manager')
            ->assertJsonPath('data.members.0.user.email', 'alice@example.test');

        $this->assertDatabaseHas('pipeline_board_members', [
            'board_id' => $board->id,
            'user_id' => $alice->id,
            'role' => 'manager',
        ]);
    }

    public function test_board_show_exposes_member_payload_and_owner_role(): void
    {
        $board = $this->sharedBoard();
        $alice = $this->staff(['email' => 'alice@example.test']);
        PipelineBoardMember::create([
            'board_id' => $board->id,
            'user_id' => $alice->id,
            'role' => 'contributor',
        ]);

        $this->withHeaders($this->headers($this->token))
            ->getJson("/api/v1/pipeline/boards/{$board->id}")
            ->assertOk()
            ->assertJsonPath('data.current_member_role', 'manager')
            ->assertJsonPath('data.members.0.user_id', $alice->id)
            ->assertJsonPath('data.members.0.role', 'contributor')
            ->assertJsonPath('data.members.0.user.email', 'alice@example.test');
    }

    public function test_patch_board_updates_member_role_in_place(): void
    {
        $board = $this->sharedBoard();
        $alice = $this->staff();
        PipelineBoardMember::create([
            'board_id' => $board->id,
            'user_id' => $alice->id,
            'role' => 'viewer',
        ]);

        $this->withHeaders($this->headers($this->token))
            ->patchJson("/api/v1/pipeline/boards/{$board->id}", [
                'members' => [['user_id' => $alice->id, 'role' => 'contributor']],
            ])
            ->assertOk()
            ->assertJsonPath('data.members.0.role', 'contributor');

        $this->assertDatabaseHas('pipeline_board_members', [
            'board_id' => $board->id,
            'user_id' => $alice->id,
            'role' => 'contributor',
        ]);
    }

    public function test_patch_board_with_empty_members_removes_roster(): void
    {
        $board = $this->sharedBoard();
        $alice = $this->staff();
        PipelineBoardMember::create(['board_id' => $board->id, 'user_id' => $alice->id, 'role' => 'viewer']);

        $this->withHeaders($this->headers($this->token))
            ->patchJson("/api/v1/pipeline/boards/{$board->id}", ['members' => []])
            ->assertOk()
            ->assertJsonPath('data.members', []);

        $this->assertDatabaseMissing('pipeline_board_members', [
            'board_id' => $board->id,
            'user_id' => $alice->id,
        ]);
    }

    public function test_owner_is_never_added_to_or_removed_from_roster(): void
    {
        $board = $this->sharedBoard();
        $alice = $this->staff();

        $this->withHeaders($this->headers($this->token))
            ->patchJson("/api/v1/pipeline/boards/{$board->id}", [
                'members' => [
                    ['user_id' => $this->owner->id, 'role' => 'viewer'],
                    ['user_id' => $alice->id, 'role' => 'viewer'],
                ],
            ])
            ->assertOk();

        $memberIds = $this->withHeaders($this->headers($this->token))
            ->getJson("/api/v1/pipeline/boards/{$board->id}")
            ->collect('data.members')->pluck('user_id')->all();

        $this->assertContains($alice->id, $memberIds);
        $this->assertNotContains($this->owner->id, $memberIds);
    }

    public function test_contributor_cannot_modify_members(): void
    {
        $board = $this->sharedBoard();
        $alice = $this->staff();
        PipelineBoardMember::create([
            'board_id' => $board->id,
            'user_id' => $alice->id,
            'role' => 'contributor',
        ]);
        $aliceToken = $alice->createToken('staff')->plainTextToken;

        $this->withHeaders($this->headers($aliceToken))
            ->patchJson("/api/v1/pipeline/boards/{$board->id}", [
                'members' => [['user_id' => $alice->id, 'role' => 'manager']],
            ])
            ->assertStatus(403);
    }

    public function test_viewer_can_view_but_has_read_only_access(): void
    {
        $board = $this->sharedBoard();
        $alice = $this->staff();
        PipelineBoardMember::create([
            'board_id' => $board->id,
            'user_id' => $alice->id,
            'role' => 'viewer',
        ]);
        $aliceToken = $alice->createToken('staff')->plainTextToken;

        $this->withHeaders($this->headers($aliceToken))
            ->getJson("/api/v1/pipeline/boards/{$board->id}")
            ->assertOk()
            ->assertJsonPath('data.current_member_role', 'viewer')
            ->assertJsonPath('data.can_contribute', false);

        $this->withHeaders($this->headers($aliceToken))
            ->patchJson('/api/v1/pipeline/boards/' . $board->id, ['members' => []])
            ->assertStatus(403);
    }

    public function test_manager_member_can_update_members(): void
    {
        $board = $this->sharedBoard();
        $alice = $this->staff();
        $bob = $this->staff();
        PipelineBoardMember::create([
            'board_id' => $board->id,
            'user_id' => $alice->id,
            'role' => 'manager',
        ]);
        $aliceToken = $alice->createToken('staff')->plainTextToken;

        $this->withHeaders($this->headers($aliceToken))
            ->patchJson("/api/v1/pipeline/boards/{$board->id}", [
                'members' => [
                    ['user_id' => $alice->id, 'role' => 'manager'],
                    ['user_id' => $bob->id, 'role' => 'viewer'],
                ],
            ])
            ->assertOk();

        $memberIds = collect($this->withHeaders($this->headers($aliceToken))
            ->getJson("/api/v1/pipeline/boards/{$board->id}")
            ->json('data.members'))->pluck('user_id')->all();

        $this->assertContains($bob->id, $memberIds);
    }

    public function test_plan_member_limit_rejects_more_members_than_allowed(): void
    {
        $smallPlan = Plan::create([
            'name' => 'Tiny',
            'slug' => 'tiny-test',
            'description' => 'Test plan',
            'price_monthly_usd' => 1,
            'features' => ['sales' => true, 'pipeline' => true],
            'limits' => ['max_board_members' => 2],
            'is_active' => true,
            'sort_order' => 10,
        ]);
        Subscription::where('business_id', $this->business->id)->update(['plan_id' => $smallPlan->id]);

        $board = $this->sharedBoard();
        $alice = $this->staff();
        $bob = $this->staff();
        $cara = $this->staff();

        $this->withHeaders($this->headers($this->token))
            ->patchJson("/api/v1/pipeline/boards/{$board->id}", [
                'members' => [
                    ['user_id' => $alice->id, 'role' => 'viewer'],
                    ['user_id' => $bob->id, 'role' => 'viewer'],
                    ['user_id' => $cara->id, 'role' => 'viewer'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('members');

        $this->assertDatabaseMissing('pipeline_board_members', ['board_id' => $board->id]);
    }

    public function test_member_with_pipeline_module_sees_board_in_list(): void
    {
        $board = $this->sharedBoard();
        $alice = $this->staff(['modules' => ['pipeline']]);
        PipelineBoardMember::create([
            'board_id' => $board->id,
            'user_id' => $alice->id,
            'role' => 'contributor',
        ]);
        $aliceToken = $alice->createToken('staff')->plainTextToken;

        $ids = $this->withHeaders($this->headers($aliceToken))
            ->getJson('/api/v1/pipeline/boards')
            ->assertOk()
            ->collect('data')->pluck('id')->all();

        $this->assertContains($board->id, $ids);
    }

    public function test_owner_cannot_invite_storefront_buyer_without_pipeline_access(): void
    {
        $board = $this->sharedBoard();
        $buyer = User::factory()->create([
            'business_id' => null,
            'is_active' => true,
            'modules' => [],
        ]);

        $this->withHeaders($this->headers($this->token))
            ->patchJson("/api/v1/pipeline/boards/{$board->id}", [
                'members' => [['user_id' => $buyer->id, 'role' => 'viewer']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('members');

        $this->assertDatabaseMissing('pipeline_board_members', ['board_id' => $board->id]);
    }

    public function test_owner_cannot_invite_external_user_on_plan_without_pipeline(): void
    {
        $board = $this->sharedBoard();
        $externalOwner = User::factory()->create(['is_active' => true]);
        $externalBusiness = Business::factory()->create([
            'owner_id' => $externalOwner->id,
            'currency' => 'USD',
            'status' => 'active',
        ]);
        $externalOwner->update(['business_id' => $externalBusiness->id]);
        $this->ensureSubscription($externalBusiness->id, Plan::where('slug', 'essential')->first()?->id);

        $this->withHeaders($this->headers($this->token))
            ->patchJson("/api/v1/pipeline/boards/{$board->id}", [
                'members' => [['user_id' => $externalOwner->id, 'role' => 'viewer']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('members');

        $this->assertDatabaseMissing('pipeline_board_members', ['board_id' => $board->id]);
    }

    public function test_owner_can_invite_external_user_on_plan_with_pipeline(): void
    {
        $board = $this->sharedBoard();
        $externalOwner = User::factory()->create(['is_active' => true]);
        $externalBusiness = Business::factory()->create([
            'owner_id' => $externalOwner->id,
            'currency' => 'USD',
            'status' => 'active',
        ]);
        $externalOwner->update(['business_id' => $externalBusiness->id]);
        $this->ensureSubscription($externalBusiness->id, Plan::where('slug', 'professional')->first()?->id);

        $this->withHeaders($this->headers($this->token))
            ->patchJson("/api/v1/pipeline/boards/{$board->id}", [
                'members' => [['user_id' => $externalOwner->id, 'role' => 'contributor']],
            ])
            ->assertOk()
            ->assertJsonPath('data.members.0.user_id', $externalOwner->id);

        $this->assertDatabaseHas('pipeline_board_members', [
            'board_id' => $board->id,
            'user_id' => $externalOwner->id,
            'role' => 'contributor',
        ]);
    }

    public function test_editor_role_is_normalized_to_contributor(): void
    {
        $board = $this->sharedBoard();
        $alice = $this->staff();

        $this->withHeaders($this->headers($this->token))
            ->patchJson("/api/v1/pipeline/boards/{$board->id}", [
                'members' => [['user_id' => $alice->id, 'role' => 'editor']],
            ])
            ->assertOk()
            ->assertJsonPath('data.members.0.role', 'contributor');

        $this->assertDatabaseHas('pipeline_board_members', [
            'board_id' => $board->id,
            'user_id' => $alice->id,
            'role' => 'contributor',
        ]);
    }
}