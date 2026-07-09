<?php

namespace Tests\Unit\Services;

use App\Models\Business;
use App\Models\PipelineBoard;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Pipeline\PipelineGoalDecompositionService;
use Carbon\Carbon;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineGoalDecompositionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PipelineGoalDecompositionService $service;

    protected Business $business;

    protected User $owner;

    protected PipelineBoard $board;

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

        $this->board = PipelineBoard::query()->create([
            'business_id' => $this->business->id,
            'name' => 'Progress test board',
            'visibility' => 'private',
            'workspace' => 'pipeline',
            'is_default' => false,
            'created_by' => $this->owner->id,
        ]);

        $this->stageId = PipelineStage::query()->create([
            'business_id' => $this->business->id,
            'board_id' => $this->board->id,
            'name' => 'Qualified',
            'sort_order' => 1,
            'color' => '#6366f1',
        ])->id;

        $this->service = app(PipelineGoalDecompositionService::class);
    }

    public function test_preview_without_members_uses_null_member_user_id(): void
    {
        $preview = $this->service->preview($this->business->id, $this->board, [
            'planning_level' => 'month',
            'target_value' => 100,
            'stage_ids' => [$this->stageId],
            'decomposition_mode' => 'equal',
        ]);

        $this->assertNotEmpty($preview['nodes']);
        foreach ($preview['nodes'] as $node) {
            $this->assertNull($node['member_user_id']);
            $this->assertSame($this->stageId, $node['stage_id']);
            $this->assertIsFloat($node['expected_value']);
        }
    }

    public function test_preview_splits_target_across_members(): void
    {
        $memberA = User::factory()->create(['business_id' => $this->business->id, 'is_active' => true]);
        $memberB = User::factory()->create(['business_id' => $this->business->id, 'is_active' => true]);

        $preview = $this->service->preview($this->business->id, $this->board, [
            'planning_level' => 'month',
            'target_value' => 100,
            'stage_ids' => [$this->stageId],
            'member_user_ids' => [$memberA->id, $memberB->id],
            'decomposition_mode' => 'equal',
        ]);

        $rootNodes = array_values(array_filter(
            $preview['nodes'],
            fn (array $node) => $node['planning_level'] === 'month',
        ));

        $this->assertNotEmpty($rootNodes);
        $memberIds = array_unique(array_map(fn (array $node) => $node['member_user_id'], $rootNodes));
        $this->assertContains($memberA->id, $memberIds);
        $this->assertContains($memberB->id, $memberIds);
    }

    public function test_preview_requires_at_least_one_stage(): void
    {
        $emptyBoard = PipelineBoard::query()->create([
            'business_id' => $this->business->id,
            'name' => 'Empty board',
            'visibility' => 'private',
            'workspace' => 'pipeline',
            'is_default' => false,
            'created_by' => $this->owner->id,
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->service->preview($this->business->id, $emptyBoard, [
            'planning_level' => 'month',
            'target_value' => 50,
            'stage_ids' => [],
            'decomposition_mode' => 'equal',
        ]);
    }

    public function test_expected_to_date_prorates_by_elapsed_days(): void
    {
        $start = Carbon::parse('2026-01-01');
        $end = Carbon::parse('2026-01-10');
        $mid = Carbon::parse('2026-01-05');

        $expected = $this->service->expectedToDate(100.0, $start, $end, $mid);

        $this->assertGreaterThan(0, $expected);
        $this->assertLessThan(100.0, $expected);
    }

    public function test_default_anchor_bounds_for_year(): void
    {
        $start = Carbon::parse($this->service->defaultAnchorStart('year', 2026));
        $end = Carbon::parse($this->service->defaultAnchorEnd('year', $start));

        $this->assertSame('2026-01-01', $start->toDateString());
        $this->assertSame('2026-12-31', $end->toDateString());
    }
}
