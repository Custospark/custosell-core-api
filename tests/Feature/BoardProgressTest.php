<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PipelineBoardTarget;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoardProgressTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected string $token;

    protected int $boardId;

    protected int $stageId;

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

        $boards = $this->authGet('/api/v1/pipeline/boards')
            ->assertOk()
            ->json('data');

        $this->boardId = (int) $boards[0]['id'];

        $kanban = $this->authGet("/api/v1/pipeline/boards/{$this->boardId}/kanban")
            ->assertOk()
            ->json('data');

        $this->stageId = (int) $kanban['stages'][0]['id'];
    }

    public function test_decompose_preview_returns_nodes_for_board_scope(): void
    {
        $response = $this->authPost("/api/v1/pipeline/boards/{$this->boardId}/targets/decompose-preview", [
            'planning_level' => 'month',
            'target_value' => 120,
            'stage_ids' => [$this->stageId],
            'decomposition_mode' => 'hybrid',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.planning_level', 'month')
            ->assertJsonPath('data.target_value', 120)
            ->assertJsonPath('data.stage_ids.0', $this->stageId);

        $nodes = $response->json('data.nodes');
        $this->assertIsArray($nodes);
        $this->assertNotEmpty($nodes);
        $this->assertNull($nodes[0]['member_user_id']);
        $this->assertArrayHasKey('expected_value', $nodes[0]);
        $this->assertArrayHasKey('period_start', $nodes[0]);
        $this->assertArrayHasKey('period_end', $nodes[0]);
    }

    public function test_decompose_preview_validates_required_fields(): void
    {
        $this->authPost("/api/v1/pipeline/boards/{$this->boardId}/targets/decompose-preview", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['planning_level', 'target_value', 'stage_ids']);
    }

    public function test_progress_summary_returns_team_and_targets_shape(): void
    {
        $response = $this->authGet("/api/v1/pipeline/boards/{$this->boardId}/progress/summary", [
            'period' => 'month',
            'stage_ids' => [$this->stageId],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.board_id', $this->boardId)
            ->assertJsonPath('data.period.type', 'month')
            ->assertJsonStructure([
                'data' => [
                    'team',
                    'members',
                    'trends',
                    'funnel',
                    'targets',
                    'stages',
                    'selected_stage_ids',
                    'can_manage_targets',
                ],
            ]);
    }

    public function test_progress_query_accepts_metrics_and_stage_filters(): void
    {
        $response = $this->authGet("/api/v1/pipeline/boards/{$this->boardId}/progress/query", [
            'period' => 'month',
            'stage_ids' => [$this->stageId],
            'metrics' => ['cards_created', 'cards_won'],
            'planning_level' => 'month',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'period',
                    'series',
                    'column_metrics',
                    'column_trends',
                ],
            ]);
    }

    public function test_my_progress_returns_current_user_slice(): void
    {
        $response = $this->authGet("/api/v1/pipeline/boards/{$this->boardId}/progress/my", [
            'period' => 'month',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user_id', $this->owner->id)
            ->assertJsonStructure([
                'data' => [
                    'metrics',
                    'targets',
                    'team_average',
                    'pace_alerts',
                    'column_metrics',
                ],
            ]);
    }

    public function test_progress_config_round_trip(): void
    {
        $payload = [
            'charts' => [
                ['id' => 'team-funnel', 'visible' => true, 'order' => 0],
            ],
        ];

        $this->authPut("/api/v1/pipeline/boards/{$this->boardId}/progress/config", $payload)
            ->assertOk()
            ->assertJsonPath('data.charts.0.id', 'team-funnel');

        $this->authGet("/api/v1/pipeline/boards/{$this->boardId}/progress/config")
            ->assertOk()
            ->assertJsonPath('data.charts.0.visible', true);
    }

    public function test_store_target_auto_persists_decomposition_allocations(): void
    {
        $create = $this->authPost("/api/v1/pipeline/boards/{$this->boardId}/targets", [
            'type' => 'kpi',
            'title' => 'Monthly wins',
            'metric_key' => 'cards_won',
            'target_value' => 24,
            'unit' => 'count',
            'period_type' => 'month',
            'planning_level' => 'month',
            'scope' => 'board',
            'stage_id' => $this->stageId,
            'decomposition_mode' => 'equal',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.title', 'Monthly wins')
            ->assertJsonPath('data.stage_id', $this->stageId);

        $targetId = (int) $create->json('data.id');

        $this->assertDatabaseHas('pipeline_board_targets', [
            'id' => $targetId,
            'board_id' => $this->boardId,
            'planning_level' => 'month',
        ]);

        $this->assertGreaterThan(
            0,
            PipelineBoardTarget::query()->findOrFail($targetId)->allocations()->count(),
        );
    }

    public function test_year_target_month_view_returns_period_slice(): void
    {
        $create = $this->authPost("/api/v1/pipeline/boards/{$this->boardId}/targets", [
            'type' => 'goal',
            'title' => 'Annual wins',
            'metric_key' => 'cards_won',
            'target_value' => 120,
            'unit' => 'count',
            'period_type' => 'month',
            'planning_level' => 'year',
            'scope' => 'board',
            'stage_id' => $this->stageId,
            'decomposition_mode' => 'equal',
        ])->assertCreated();

        $targetId = (int) $create->json('data.id');

        $summary = $this->authGet("/api/v1/pipeline/boards/{$this->boardId}/progress/summary", [
            'period' => 'month',
            'stage_ids' => [$this->stageId],
        ])->assertOk();

        $targets = collect($summary->json('data.targets'));
        $target = $targets->firstWhere('id', $targetId);
        $this->assertNotNull($target);
        $this->assertArrayHasKey('period_slice', $target);
        $this->assertSame('month', $target['period_slice']['planning_level']);
        $this->assertSame(120.0, (float) $target['target_value']);
        $this->assertLessThan(120.0, (float) $target['period_slice']['expected_value']);
        $this->assertSame(120.0, (float) $target['period_slice']['root_target_value']);
    }

    public function test_month_target_day_view_prorates_expected_to_period_ratio(): void
    {
        $create = $this->authPost("/api/v1/pipeline/boards/{$this->boardId}/targets", [
            'type' => 'goal',
            'title' => 'Monthly wins',
            'metric_key' => 'cards_won',
            'target_value' => 60,
            'unit' => 'count',
            'period_type' => 'month',
            'planning_level' => 'month',
            'scope' => 'board',
            'stage_id' => $this->stageId,
            'decomposition_mode' => 'equal',
        ])->assertCreated();

        $targetId = (int) $create->json('data.id');

        $summary = $this->authGet("/api/v1/pipeline/boards/{$this->boardId}/progress/summary", [
            'period' => 'day',
            'stage_ids' => [$this->stageId],
        ])->assertOk();

        $targets = collect($summary->json('data.targets'));
        $target = $targets->firstWhere('id', $targetId);
        $this->assertNotNull($target);
        $this->assertArrayHasKey('period_slice', $target);

        $daysInMonth = (int) now()->daysInMonth;
        $expectedDaily = 60 / $daysInMonth;

        $this->assertSame('day', $target['period_slice']['planning_level']);
        $this->assertEqualsWithDelta($expectedDaily, (float) $target['period_slice']['expected_value'], 0.05);
        $this->assertSame(60.0, (float) $target['period_slice']['root_target_value']);
        $this->assertSame(now()->toDateString(), $target['period_slice']['period_start']);
        $this->assertSame(now()->toDateString(), $target['period_slice']['period_end']);
    }

    public function test_list_and_archive_targets(): void
    {
        $create = $this->authPost("/api/v1/pipeline/boards/{$this->boardId}/targets", [
            'type' => 'goal',
            'title' => 'Pipeline value',
            'metric_key' => 'pipeline_value_won',
            'target_value' => 1000000,
            'unit' => 'currency',
            'period_type' => 'quarter',
            'planning_level' => 'quarter',
            'scope' => 'board',
            'stage_id' => $this->stageId,
        ])->assertCreated();

        $targetId = (int) $create->json('data.id');

        $this->authGet("/api/v1/pipeline/boards/{$this->boardId}/targets")
            ->assertOk()
            ->assertJsonFragment(['id' => $targetId, 'title' => 'Pipeline value']);

        $this->authDelete("/api/v1/pipeline/targets/{$targetId}")
            ->assertNoContent();

        $this->assertDatabaseHas('pipeline_board_targets', [
            'id' => $targetId,
            'status' => 'archived',
        ]);
    }

    public function test_export_board_progress_returns_download_payload(): void
    {
        $response = $this->authGet("/api/v1/pipeline/boards/{$this->boardId}/progress/export", [
            'period' => 'month',
            'stage_ids' => [$this->stageId],
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }

  /** @param  array<string, mixed>  $query */
    protected function authGet(string $uri, array $query = [])
    {
        $url = $query === [] ? $uri : $uri.'?'.http_build_query($query);

        return $this->withHeader('Authorization', "Bearer {$this->token}")->getJson($url);
    }

    /** @param  array<string, mixed>  $payload */
    protected function authPost(string $uri, array $payload = [])
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}")->postJson($uri, $payload);
    }

    /** @param  array<string, mixed>  $payload */
    protected function authPut(string $uri, array $payload = [])
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}")->putJson($uri, $payload);
    }

    protected function authDelete(string $uri)
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}")->deleteJson($uri);
    }
}
