<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PipelineBoard;
use App\Models\PipelineBoardMember;
use App\Models\PipelineLead;
use App\Models\PipelineStage;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineReminderPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->seed(SystemRoleSeeder::class);
    }

    private function makeBoard(string $visibility): array
    {
        $owner = User::factory()->create(['is_active' => true]);
        $business = Business::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);
        $owner->update(['business_id' => $business->id]);
        $this->ensureSubscription($business->id, Plan::where('slug', 'professional')->first()?->id);

        $board = PipelineBoard::create([
            'business_id' => $business->id,
            'created_by' => $owner->id,
            'name' => 'Board ' . $visibility,
            'visibility' => $visibility,
            'workspace' => 'pipeline',
        ]);
        $stage = PipelineStage::create(['business_id' => $business->id, 'board_id' => $board->id, 'name' => 'New', 'sort_order' => 1]);

        return [$owner, $business, $board, $stage];
    }

    private function createReminder(int $leadId, string $token): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/v1/pipeline/leads/{$leadId}/reminders", [
                'remind_at' => now()->addHour()->toISOString(),
                'message' => 'Call them',
                'channel' => 'both',
            ]);
    }

    public function test_creator_can_create_reminder_on_private_board(): void
    {
        [$owner, $business, $board, $stage] = $this->makeBoard('private');
        $lead = PipelineLead::create([
            'business_id' => $business->id, 'board_id' => $board->id, 'stage_id' => $stage->id,
            'created_by' => $owner->id, 'title' => 'Deal', 'card_type' => 'lead', 'status' => 'open', 'stage_entered_at' => now(),
        ]);

        $this->createReminder($lead->id, $owner->createToken('owner')->plainTextToken)->assertCreated();
    }

    public function test_manager_member_can_create_reminder_on_private_board(): void
    {
        [$owner, $business, $board, $stage] = $this->makeBoard('private');
        $manager = User::factory()->create(['business_id' => $business->id, 'is_active' => true, 'modules' => ['pipeline']]);
        PipelineBoardMember::create(['board_id' => $board->id, 'user_id' => $manager->id, 'role' => 'manager']);

        $lead = PipelineLead::create([
            'business_id' => $business->id, 'board_id' => $board->id, 'stage_id' => $stage->id,
            'created_by' => $owner->id, 'title' => 'Deal', 'card_type' => 'lead', 'status' => 'open', 'stage_entered_at' => now(),
        ]);

        $this->createReminder($lead->id, $manager->createToken('manager')->plainTextToken)->assertCreated();
    }

    public function test_manager_member_can_create_reminder_on_shared_board(): void
    {
        [$owner, $business, $board, $stage] = $this->makeBoard('shared');
        $manager = User::factory()->create(['business_id' => $business->id, 'is_active' => true, 'modules' => ['pipeline']]);
        PipelineBoardMember::create(['board_id' => $board->id, 'user_id' => $manager->id, 'role' => 'manager']);

        $lead = PipelineLead::create([
            'business_id' => $business->id, 'board_id' => $board->id, 'stage_id' => $stage->id,
            'created_by' => $owner->id, 'title' => 'Deal', 'card_type' => 'lead', 'status' => 'open', 'stage_entered_at' => now(),
        ]);

        $this->createReminder($lead->id, $manager->createToken('manager')->plainTextToken)->assertCreated();
    }
}