<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PipelineAutomationRule;
use App\Models\PipelineLead;
use App\Models\Plan;
use App\Models\User;
use App\Services\Pipeline\PipelineAutomationSchedulerService;

class PipelineAutomationTest extends PipelineAutomationTestCase
{
    public function test_list_rules_requires_board_scope(): void
    {
        $this->createRule();

        $this->withHeaders($this->headers($this->token))
            ->getJson("/api/v1/pipeline/boards/{$this->board->id}/automation-rules")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure(['data' => ['*' => ['id', 'name', 'trigger', 'actions', 'is_active', 'run_count']]]);
    }

    public function test_create_rule_validates_and_persists(): void
    {
        $response = $this->withHeaders($this->headers($this->token))
            ->postJson("/api/v1/pipeline/boards/{$this->board->id}/automation-rules", [
                'name' => 'Move qualified',
                'trigger' => ['type' => 'status_changed'],
                'conditions' => [['field' => 'priority', 'operator' => 'is', 'value' => 'high']],
                'actions' => [['type' => 'move_to_stage', 'stage_id' => $this->stageB->id]],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Move qualified')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('pipeline_automation_rules', [
            'business_id' => $this->business->id,
            'board_id' => $this->board->id,
            'name' => 'Move qualified',
        ]);

        $this->withHeaders($this->headers($this->token))
            ->postJson("/api/v1/pipeline/boards/{$this->board->id}/automation-rules", [
                'name' => '',
                'trigger' => [],
                'actions' => [],
            ])
            ->assertStatus(422);
    }

    public function test_update_and_delete_rule(): void
    {
        $rule = $this->createRule();

        $this->withHeaders($this->headers($this->token))
            ->putJson("/api/v1/pipeline/automation-rules/{$rule->id}", [
                'name' => 'Renamed rule',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed rule')
            ->assertJsonPath('data.is_active', false);

        $this->withHeaders($this->headers($this->token))
            ->patchJson("/api/v1/pipeline/automation-rules/{$rule->id}/toggle", [
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->withHeaders($this->headers($this->token))
            ->deleteJson("/api/v1/pipeline/automation-rules/{$rule->id}")
            ->assertOk();

        $this->assertSoftDeleted('pipeline_automation_rules', ['id' => $rule->id]);
    }

    public function test_cross_business_rule_is_forbidden(): void
    {
        $rule = $this->createRule();

        $intruder = User::factory()->create(['is_active' => true]);
        $otherBusiness = Business::factory()->create(['owner_id' => $intruder->id, 'status' => 'active']);
        $intruder->update(['business_id' => $otherBusiness->id]);
        $this->ensureSubscription($otherBusiness->id, Plan::where('slug', 'professional')->first()?->id);
        $otherToken = $intruder->createToken('other')->plainTextToken;

        $this->withHeaders($this->headers($otherToken))
            ->putJson("/api/v1/pipeline/automation-rules/{$rule->id}", ['name' => 'Hijack'])
            ->assertStatus(404);
    }

    public function test_stage_entered_event_fires_action(): void
    {
        $rule = $this->createRule([
            'actions' => [['type' => 'set_priority', 'priority' => 'high']],
        ]);

        $lead = $this->createLead();

        $this->withHeaders($this->headers($this->token))
            ->patchJson("/api/v1/pipeline/leads/{$lead->id}/stage", [
                'stage_id' => $this->stageB->id,
                'position' => 1,
            ])
            ->assertOk();

        $this->assertSame(1, $rule->fresh()->run_count);
        $this->assertDatabaseHas('pipeline_leads', [
            'id' => $lead->id,
            'priority' => 'high',
        ]);
    }

    public function test_scheduler_runs_due_passed_trigger_with_frequency(): void
    {
        $this->createRule([
            'trigger' => ['type' => 'due_date_passed', 'frequency' => 'daily', 'time' => $this->nowTime()],
            'actions' => [['type' => 'set_priority', 'priority' => 'urgent']],
        ]);

        $lead = $this->createLead([
            'due_date' => now()->subDay(),
            'priority' => 'low',
        ]);

        $scheduler = app(PipelineAutomationSchedulerService::class);
        $result = $scheduler->runDue();

        $this->assertSame(1, $result['fired']);
        $this->assertSame(1, $result['executed']);
        $this->assertDatabaseHas('pipeline_leads', [
            'id' => $lead->id,
            'priority' => 'urgent',
        ]);
    }

    public function test_scheduler_is_idempotent_within_a_run(): void
    {
        $this->createRule([
            'trigger' => ['type' => 'due_date_passed', 'frequency' => 'once'],
            'actions' => [['type' => 'set_priority', 'priority' => 'urgent']],
        ]);

        $this->createLead(['due_date' => now()->subDay(), 'priority' => 'low']);

        $scheduler = app(PipelineAutomationSchedulerService::class);
        $first = $scheduler->runDue();
        $second = $scheduler->runDue();

        $this->assertSame(1, $first['fired']);
        $this->assertSame(0, $second['fired']);
    }

    public function test_recurring_rule_creates_one_card_per_window(): void
    {
        $this->createRule([
            'trigger' => ['type' => 'recurring', 'frequency' => 'weekly', 'days_of_week' => [0, 1, 2, 3, 4, 5, 6], 'time' => $this->nowTime()],
            'actions' => [['type' => 'create_card', 'title' => 'Weekly review']],
        ]);

        $scheduler = app(PipelineAutomationSchedulerService::class);

        $first = $scheduler->runDue();
        $second = $scheduler->runDue();

        $this->assertSame(1, $first['executed']);
        $this->assertSame(0, $second['executed']);
        $this->assertDatabaseHas('pipeline_leads', [
            'board_id' => $this->board->id,
            'title' => 'Weekly review',
        ]);
    }

    public function test_created_card_does_not_retrigger_event_rules(): void
    {
        $this->createRule([
            'trigger' => ['type' => 'card_created'],
            'actions' => [['type' => 'set_priority', 'priority' => 'high']],
        ]);
        $this->createRule([
            'trigger' => ['type' => 'recurring', 'frequency' => 'daily', 'time' => $this->nowTime()],
            'actions' => [['type' => 'create_card', 'title' => 'Auto card']],
        ]);

        $scheduler = app(PipelineAutomationSchedulerService::class);
        $scheduler->runDue();

        $autoCard = PipelineLead::query()->where('title', 'Auto card')->first();
        $this->assertNotNull($autoCard);
        $this->assertNotSame('high', $autoCard->priority);
    }

    public function test_conditions_gate_event_firing(): void
    {
        $this->createRule([
            'conditions' => [['field' => 'priority', 'operator' => 'is', 'value' => 'high']],
            'actions' => [['type' => 'set_priority', 'priority' => 'urgent']],
        ]);

        $low = $this->createLead(['priority' => 'low']);
        $this->withHeaders($this->headers($this->token))
            ->patchJson("/api/v1/pipeline/leads/{$low->id}/stage", [
                'stage_id' => $this->stageB->id,
                'position' => 1,
            ])
            ->assertOk();

        $rule = PipelineAutomationRule::query()->first();
        $this->assertSame(0, $rule->fresh()->run_count);

        $high = $this->createLead(['priority' => 'high']);
        $this->withHeaders($this->headers($this->token))
            ->patchJson("/api/v1/pipeline/leads/{$high->id}/stage", [
                'stage_id' => $this->stageB->id,
                'position' => 1,
            ])
            ->assertOk();

        $this->assertSame(1, $rule->fresh()->run_count);
        $this->assertDatabaseHas('pipeline_leads', [
            'id' => $high->id,
            'priority' => 'urgent',
        ]);
    }
}