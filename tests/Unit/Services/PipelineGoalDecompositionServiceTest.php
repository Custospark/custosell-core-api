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

    public function test_default_anchor_bounds_for_decade_and_five_year_are_rolling(): void
    {
        $decadeStart = Carbon::parse($this->service->defaultAnchorStart('decade', 2026));
        $decadeEnd = Carbon::parse($this->service->defaultAnchorEnd('decade', $decadeStart));
        $this->assertSame('2026-01-01', $decadeStart->toDateString());
        $this->assertSame('2035-12-31', $decadeEnd->toDateString());

        $fiveStart = Carbon::parse($this->service->defaultAnchorStart('five_year', 2026));
        $fiveEnd = Carbon::parse($this->service->defaultAnchorEnd('five_year', $fiveStart));
        $this->assertSame('2026-01-01', $fiveStart->toDateString());
        $this->assertSame('2030-12-31', $fiveEnd->toDateString());
    }

    public function test_preview_uses_day_weighted_shares_not_equal_buckets(): void
    {
        // Non-leap 2025: Jan=31, Feb=28 — equal buckets would assign identical month shares.
        $preview = $this->service->preview($this->business->id, $this->board, [
            'planning_level' => 'year',
            'target_value' => 365,
            'anchor_start' => '2025-01-01',
            'anchor_end' => '2025-12-31',
            'stage_ids' => [$this->stageId],
            'decomposition_mode' => 'equal',
        ]);

        $months = collect($preview['nodes'])->where('planning_level', 'month')->values();
        $jan = $months->first(fn (array $n) => $n['period_start'] === '2025-01-01');
        $feb = $months->first(fn (array $n) => $n['period_start'] === '2025-02-01');

        $this->assertNotNull($jan);
        $this->assertNotNull($feb);
        $this->assertEqualsWithDelta(31.0, (float) $jan['expected_value'], 0.01);
        $this->assertEqualsWithDelta(28.0, (float) $feb['expected_value'], 0.01);
        $this->assertNotEquals(
            round((float) $jan['expected_value'], 4),
            round((float) $feb['expected_value'], 4),
        );
    }

    public function test_preview_cascades_from_parent_not_flat_root_division(): void
    {
        $preview = $this->service->preview($this->business->id, $this->board, [
            'planning_level' => 'year',
            'target_value' => 365,
            'anchor_start' => '2025-01-01',
            'anchor_end' => '2025-12-31',
            'stage_ids' => [$this->stageId],
            'decomposition_mode' => 'equal',
        ]);

        $q1 = collect($preview['nodes'])->first(
            fn (array $n) => $n['planning_level'] === 'quarter' && $n['period_start'] === '2025-01-01',
        );
        $this->assertNotNull($q1);
        // Q1 2025 = 31+28+31 = 90 days
        $this->assertEqualsWithDelta(90.0, (float) $q1['expected_value'], 0.01);

        $q1Months = collect($preview['nodes'])->filter(
            fn (array $n) => $n['planning_level'] === 'month'
                && $n['period_start'] >= '2025-01-01'
                && $n['period_start'] <= '2025-03-01',
        );
        $this->assertEqualsWithDelta(
            (float) $q1['expected_value'],
            (float) $q1Months->sum('expected_value'),
            0.05,
        );
    }

    public function test_preview_nodes_include_cumulative_expected(): void
    {
        $preview = $this->service->preview($this->business->id, $this->board, [
            'planning_level' => 'year',
            'target_value' => 365,
            'anchor_start' => '2025-01-01',
            'anchor_end' => '2025-12-31',
            'stage_ids' => [$this->stageId],
            'decomposition_mode' => 'equal',
        ]);

        $jan = collect($preview['nodes'])->first(
            fn (array $n) => $n['planning_level'] === 'month' && $n['period_start'] === '2025-01-01',
        );
        $this->assertNotNull($jan);
        $this->assertArrayHasKey('cumulative_expected', $jan);
        // Through Jan 31 = 31/365 * 365
        $this->assertEqualsWithDelta(31.0, (float) $jan['cumulative_expected'], 0.01);

        $year = collect($preview['nodes'])->first(
            fn (array $n) => $n['planning_level'] === 'year',
        );
        $this->assertNotNull($year);
        $this->assertEqualsWithDelta(365.0, (float) $year['cumulative_expected'], 0.01);
    }

    public function test_horizon_expected_to_date_only_for_long_horizon_levels(): void
    {
        Carbon::setTestNow(Carbon::parse('2025-03-15'));

        $yearTarget = new \App\Models\PipelineBoardTarget([
            'planning_level' => 'year',
            'target_value' => 365,
            'anchor_start' => '2025-01-01',
            'anchor_end' => '2025-12-31',
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ]);

        // Day-of-year for Mar 15 2025 = 31+28+15 = 74
        $this->assertEqualsWithDelta(
            74.0,
            (float) $this->service->horizonExpectedToDate($yearTarget),
            0.01,
        );

        $monthTarget = new \App\Models\PipelineBoardTarget([
            'planning_level' => 'month',
            'target_value' => 100,
            'anchor_start' => '2025-03-01',
            'anchor_end' => '2025-03-31',
        ]);
        $this->assertNull($this->service->horizonExpectedToDate($monthTarget));

        Carbon::setTestNow();
    }
}
