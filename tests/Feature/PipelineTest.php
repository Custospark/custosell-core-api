<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PipelineBoard;
use App\Models\PipelineLead;
use App\Models\PipelineStage;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineTest extends TestCase
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
        $this->token = $this->owner->createToken('owner')->plainTextToken;
    }

    public function test_list_boards_seeds_default_pipeline(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/pipeline/boards');

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Main sales pipeline')
            ->assertJsonPath('data.0.is_default', true);

        $this->assertDatabaseHas('pipeline_boards', [
            'business_id' => $this->business->id,
            'name' => 'Main sales pipeline',
        ]);
    }

    public function test_create_lead_and_move_stage(): void
    {
        $boards = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/pipeline/boards')
            ->json('data');

        $boardId = $boards[0]['id'];
        $kanban = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/pipeline/boards/{$boardId}/kanban")
            ->assertOk()
            ->json('data');

        $firstStageId = $kanban['stages'][0]['id'];
        $secondStageId = $kanban['stages'][1]['id'];

        $create = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/pipeline/leads', [
                'board_id' => $boardId,
                'stage_id' => $firstStageId,
                'title' => 'Acme Corp deal',
                'contact_name' => 'Jane Doe',
                'contact_email' => 'jane@acme.test',
                'estimated_value' => 500000,
            ]);

        $create->assertCreated()
            ->assertJsonPath('data.title', 'Acme Corp deal')
            ->assertJsonPath('data.status', 'open');

        $leadId = $create->json('data.id');

        $move = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/v1/pipeline/leads/{$leadId}/stage", [
                'stage_id' => $secondStageId,
                'position' => 1,
            ]);

        $move->assertOk()
            ->assertJsonPath('data.stage_id', $secondStageId);
    }

    public function test_convert_lead_creates_customer(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/pipeline/boards')
            ->assertOk();

        $board = PipelineBoard::query()->where('business_id', $this->business->id)->firstOrFail();
        $stage = PipelineStage::query()->where('board_id', $board->id)->orderBy('sort_order')->firstOrFail();

        $lead = PipelineLead::query()->create([
            'business_id' => $this->business->id,
            'board_id' => $board->id,
            'stage_id' => $stage->id,
            'created_by' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'title' => 'Convert me',
            'contact_name' => 'Sam Buyer',
            'contact_email' => 'sam@buyer.test',
            'status' => 'open',
            'position' => 1,
            'currency' => 'UGX',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/pipeline/leads/{$lead->id}/convert");

        $response->assertOk()
            ->assertJsonPath('data.status', 'converted');

        $this->assertDatabaseHas('customers', [
            'business_id' => $this->business->id,
            'email' => 'sam@buyer.test',
        ]);
    }

    public function test_pipeline_module_required_for_access(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['sales'],
        ]);
        $staffToken = $staff->createToken('staff')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->getJson('/api/v1/pipeline/boards')
            ->assertForbidden();
    }
}
