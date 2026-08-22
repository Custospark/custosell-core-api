<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PipelineAutomationRule;
use App\Models\PipelineBoard;
use App\Models\PipelineLead;
use App\Models\PipelineStage;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class PipelineAutomationTestCase extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected string $token;

    protected PipelineBoard $board;

    protected PipelineStage $stageA;

    protected PipelineStage $stageB;

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

        $this->board = PipelineBoard::create([
            'business_id' => $this->business->id,
            'created_by' => $this->owner->id,
            'name' => 'Automation board',
            'visibility' => 'private',
            'workspace' => 'pipeline',
        ]);

        $this->stageA = PipelineStage::create([
            'business_id' => $this->business->id,
            'board_id' => $this->board->id,
            'name' => 'New',
            'sort_order' => 1,
        ]);

        $this->stageB = PipelineStage::create([
            'business_id' => $this->business->id,
            'board_id' => $this->board->id,
            'name' => 'Qualified',
            'sort_order' => 2,
        ]);
    }

    protected function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    protected function nowTime(): string
    {
        return now()->format('H:i');
    }

    protected function createRule(array $overrides = []): PipelineAutomationRule
    {
        return PipelineAutomationRule::create(array_merge([
            'business_id' => $this->business->id,
            'board_id' => $this->board->id,
            'created_by' => $this->owner->id,
            'name' => 'Follow up hot leads',
            'trigger' => ['type' => 'stage_entered'],
            'conditions' => null,
            'actions' => [['type' => 'move_to_stage', 'stage_id' => $this->stageB->id]],
            'is_active' => true,
        ], $overrides));
    }

    protected function createLead(array $overrides = []): PipelineLead
    {
        return PipelineLead::create(array_merge([
            'business_id' => $this->business->id,
            'board_id' => $this->board->id,
            'stage_id' => $this->stageA->id,
            'created_by' => $this->owner->id,
            'title' => 'Acme Corp deal',
            'card_type' => 'lead',
            'status' => 'open',
            'stage_entered_at' => now(),
        ], $overrides));
    }
}