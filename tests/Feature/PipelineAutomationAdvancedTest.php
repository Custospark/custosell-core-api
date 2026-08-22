<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipelineAutomationRule;
use App\Models\PipelineLabel;
use App\Services\Pipeline\PipelineAutomationSchedulerService;

class PipelineAutomationAdvancedTest extends PipelineAutomationTestCase
{
    public function test_event_run_is_recorded_in_run_log(): void
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

        $this->assertDatabaseHas('pipeline_automation_runs', [
            'rule_id' => $rule->id,
            'lead_id' => $lead->id,
            'status' => 'success',
            'actions_executed' => 1,
        ]);

        $this->withHeaders($this->headers($this->token))
            ->getJson("/api/v1/pipeline/automation-rules/{$rule->id}/runs")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure(['data' => ['*' => ['id', 'status', 'actions_executed', 'created_at']]]);
    }

    public function test_scheduler_run_is_recorded_in_run_log(): void
    {
        $rule = $this->createRule([
            'trigger' => ['type' => 'due_date_passed', 'frequency' => 'daily', 'time' => $this->nowTime()],
            'actions' => [['type' => 'set_priority', 'priority' => 'urgent']],
        ]);

        $this->createLead(['due_date' => now()->subDay()]);

        app(PipelineAutomationSchedulerService::class)->runDue();

        $this->assertDatabaseHas('pipeline_automation_runs', [
            'rule_id' => $rule->id,
            'status' => 'success',
            'actions_executed' => 1,
        ]);
    }

    public function test_checklist_action_runs_on_event_trigger(): void
    {
        $rule = $this->createRule([
            'trigger' => ['type' => 'card_created'],
            'conditions' => null,
            'actions' => [['type' => 'create_checklist', 'title' => 'Onboarding']],
        ]);

        $response = $this->withHeaders($this->headers($this->token))
            ->postJson('/api/v1/pipeline/leads', [
                'board_id' => $this->board->id,
                'stage_id' => $this->stageA->id,
                'title' => 'Checklist candidate',
                'contact_name' => 'Jane Doe',
            ]);

        $response->assertCreated();
        $leadId = $response->json('data.id');

        $this->assertNotNull($rule->fresh()->run_count);
        $this->assertDatabaseHas('pipeline_checklists', [
            'lead_id' => $leadId,
            'title' => 'Onboarding',
        ]);
    }

    public function test_label_added_trigger_fires_on_label_change(): void
    {
        $rule = $this->createRule([
            'trigger' => ['type' => 'label_added'],
            'actions' => [['type' => 'set_priority', 'priority' => 'high']],
        ]);

        $lead = $this->createLead(['priority' => 'low']);
        $label = PipelineLabel::create([
            'business_id' => $this->business->id,
            'board_id' => $this->board->id,
            'name' => 'Hot',
            'color' => '#ef4444',
        ]);

        $this->withHeaders($this->headers($this->token))
            ->patchJson("/api/v1/pipeline/leads/{$lead->id}", [
                'label_ids' => [$label->id],
            ])
            ->assertOk();

        $this->assertSame(1, $rule->fresh()->run_count);
        $this->assertDatabaseHas('pipeline_leads', [
            'id' => $lead->id,
            'priority' => 'high',
        ]);
    }

    public function test_converted_trigger_fires_on_convert(): void
    {
        $rule = $this->createRule([
            'trigger' => ['type' => 'converted_to_customer'],
            'actions' => [['type' => 'set_priority', 'priority' => 'urgent']],
        ]);

        $lead = $this->createLead();

        $this->withHeaders($this->headers($this->token))
            ->postJson("/api/v1/pipeline/leads/{$lead->id}/convert", [
                'name' => 'Acme Corp',
                'email' => 'hello@acme.test',
            ])
            ->assertOk();

        $this->assertSame(1, $rule->fresh()->run_count);
        $this->assertDatabaseHas('pipeline_leads', [
            'id' => $lead->id,
            'priority' => 'urgent',
        ]);
    }

    public function test_and_or_condition_group_is_evaluated(): void
    {
        $evaluator = app(\App\Services\Pipeline\PipelineAutomationConditionEvaluator::class);

        $highUrgent = $this->createLead(['priority' => 'high', 'status' => 'open']);
        $highWon = $this->createLead(['priority' => 'high', 'status' => 'won']);

        $orGroup = ['logic' => 'or', 'conditions' => [
            ['field' => 'priority', 'operator' => 'is', 'value' => 'low'],
            ['field' => 'status', 'operator' => 'is', 'value' => 'won'],
        ]];

        $this->assertFalse($evaluator->passes($highUrgent, $orGroup));
        $this->assertTrue($evaluator->passes($highWon, $orGroup));

        $nested = ['logic' => 'and', 'conditions' => [
            ['field' => 'priority', 'operator' => 'is', 'value' => 'high'],
            ['logic' => 'or', 'conditions' => [
                ['field' => 'status', 'operator' => 'is', 'value' => 'open'],
                ['field' => 'status', 'operator' => 'is', 'value' => 'won'],
            ]],
        ]];

        $this->assertTrue($evaluator->passes($highUrgent, $nested));
        $this->assertTrue($evaluator->passes($highWon, $nested));
    }

    public function test_field_changed_trigger_fires_on_update(): void
    {
        $rule = $this->createRule([
            'trigger' => ['type' => 'field_changed'],
            'actions' => [['type' => 'set_priority', 'priority' => 'high']],
        ]);

        $lead = $this->createLead(['priority' => 'low', 'title' => 'Original']);

        $this->withHeaders($this->headers($this->token))
            ->patchJson("/api/v1/pipeline/leads/{$lead->id}", [
                'title' => 'Renamed by user',
            ])
            ->assertOk();

        $this->assertSame(1, $rule->fresh()->run_count);
        $this->assertDatabaseHas('pipeline_leads', [
            'id' => $lead->id,
            'priority' => 'high',
        ]);
    }
}