<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\PipelineBoard;
use App\Models\PipelineBoardMember;
use App\Models\PipelineStage;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-organisation board collaboration. The external collaborator OWNS their
 * own business - they must never inherit board owner/manager powers purely by
 * being a business owner in their own (unrelated) account.
 */
class PipelineBoardCrossOrgAccessTest extends TestCase
{
    use RefreshDatabase;

    protected Business $ownerBusiness;

    protected User $owner;

    protected PipelineBoard $board;

    protected string $ownerToken;

    protected Business $externalBusiness;

    protected User $externalCollaborator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->seed(SystemRoleSeeder::class);

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->ownerBusiness = Business::factory()->create([
            'owner_id' => $this->owner->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->owner->update(['business_id' => $this->ownerBusiness->id]);
        $this->ensureSubscription($this->ownerBusiness->id, Plan::where('slug', 'professional')->first()?->id);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $this->board = PipelineBoard::create([
            'business_id' => $this->ownerBusiness->id,
            'created_by' => $this->owner->id,
            'name' => 'Shared across businesses',
            'visibility' => 'shared',
            'workspace' => 'pipeline',
        ]);

        // External collaborator is the OWNER of their own business B.
        $this->externalCollaborator = User::factory()->create(['is_active' => true]);
        $this->externalBusiness = Business::factory()->create([
            'owner_id' => $this->externalCollaborator->id,
            'currency' => 'USD',
            'status' => 'active',
        ]);
        $this->externalCollaborator->update(['business_id' => $this->externalBusiness->id]);
        $this->ensureSubscription($this->externalBusiness->id, Plan::where('slug', 'professional')->first()?->id);

        PipelineBoardMember::create([
            'board_id' => $this->board->id,
            'user_id' => $this->externalCollaborator->id,
            'role' => 'contributor',
        ]);
    }

    protected function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    protected function externalToken(): string
    {
        return $this->externalCollaborator->createToken('external')->plainTextToken;
    }

    public function test_external_owner_collaborator_resolves_to_contributor_not_manager(): void
    {
        $this->withHeaders($this->headers($this->externalToken()))
            ->getJson('/api/v1/pipeline/boards/' . $this->board->id)
            ->assertOk()
            ->assertJsonPath('data.current_member_role', 'contributor')
            ->assertJsonPath('data.can_manage_settings', false)
            ->assertJsonPath('data.can_contribute', true);
    }

    public function test_external_owner_collaborator_cannot_manage_members(): void
    {
        $this->withHeaders($this->headers($this->externalToken()))
            ->patchJson('/api/v1/pipeline/boards/' . $this->board->id, [
                'members' => [['user_id' => $this->owner->id, 'role' => 'viewer']],
            ])
            ->assertStatus(403);
    }

    public function test_external_owner_collaborator_can_contribute_a_lead(): void
    {
        $stage = PipelineStage::query()->where('board_id', $this->board->id)->first()
            ?? PipelineStage::query()->create([
                'board_id' => $this->board->id,
                'business_id' => $this->ownerBusiness->id,
                'name' => 'New',
                'sort_order' => 1,
                'color' => '#6366f1',
                'is_won' => false,
                'is_lost' => false,
            ]);

        $this->withHeaders($this->headers($this->externalToken()))
            ->postJson('/api/v1/pipeline/leads', [
                'board_id' => $this->board->id,
                'stage_id' => $stage->id,
                'title' => 'Across-border deal',
            ])
            ->assertCreated()
            ->assertJsonPath('data.board_id', $this->board->id);
    }

    public function test_external_owner_on_plan_without_pipeline_is_denied(): void
    {
        Subscription::where('business_id', $this->externalBusiness->id)->update([
            'plan_id' => Plan::where('slug', 'essential')->first()?->id,
        ]);

        $this->withHeaders($this->headers($this->externalToken()))
            ->getJson('/api/v1/pipeline/boards/' . $this->board->id)
            ->assertStatus(403);
    }

    public function test_external_collaborator_without_active_subscription_is_denied(): void
    {
        Subscription::where('business_id', $this->externalBusiness->id)
            ->update(['status' => 'cancelled']);

        $this->withHeaders($this->headers($this->externalToken()))
            ->getJson('/api/v1/pipeline/boards/' . $this->board->id)
            ->assertStatus(403);
    }

    public function test_external_owner_who_is_not_invited_cannot_view_board(): void
    {
        PipelineBoardMember::query()
            ->where('board_id', $this->board->id)
            ->where('user_id', $this->externalCollaborator->id)
            ->delete();

        $this->withHeaders($this->headers($this->externalToken()))
            ->getJson('/api/v1/pipeline/boards/' . $this->board->id)
            ->assertStatus(404);
    }

    public function test_same_business_member_without_modules_can_view_shared_board(): void
    {
        $localStaff = User::factory()->create([
            'business_id' => $this->ownerBusiness->id,
            'is_active' => true,
            'modules' => [],
        ]);
        PipelineBoardMember::create([
            'board_id' => $this->board->id,
            'user_id' => $localStaff->id,
            'role' => 'viewer',
        ]);

        $this->withHeaders($this->headers($localStaff->createToken('local')->plainTextToken))
            ->getJson('/api/v1/pipeline/boards/' . $this->board->id)
            ->assertOk()
            ->assertJsonPath('data.current_member_role', 'viewer');
    }
}