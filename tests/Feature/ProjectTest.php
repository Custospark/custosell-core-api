<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsAccounting;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAccounting;

    protected Business $business;

    protected User $user;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->token = $this->user->createToken('owner')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->user->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->user->update(['business_id' => $this->business->id]);

        $this->seedAccountingForBusiness($this->business);
    }

    public function test_convert_estimate_to_project_and_record_timesheet(): void
    {
        $customer = Customer::factory()->create(['business_id' => $this->business->id]);

        $create = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/estimates', [
                'customer_id' => $customer->id,
                'title' => 'Software build',
                'line_items' => [
                    [
                        'description' => 'Development',
                        'quantity' => 1,
                        'unit_cost' => 2000,
                        'markup_type' => 'percent',
                        'markup_value' => 25,
                        'type' => 'labor',
                    ],
                ],
            ]);

        $estimateId = $create->json('id');

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/estimates/{$estimateId}/send")
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/estimates/{$estimateId}/approve", [
                'approved_by_name' => 'Client PM',
            ])
            ->assertOk();

        $convert = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/estimates/{$estimateId}/convert-to-project", [
                'name' => 'Software build project',
            ]);

        $convert->assertCreated();
        $projectId = $convert->json('id');

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'estimate_id' => $estimateId,
            'customer_id' => $customer->id,
        ]);

        $estimate = Estimate::findOrFail($estimateId);
        $this->assertEquals('converted', $estimate->status);
        $this->assertEquals($projectId, $estimate->project_id);

        $timesheet = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/projects/{$projectId}/timesheets", [
                'user_id' => $this->user->id,
                'entry_date' => now()->toDateString(),
                'hours' => 8,
                'hourly_rate' => 50000,
                'notes' => 'Sprint day 1',
            ]);

        $timesheet->assertCreated();

        $summary = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/projects/{$projectId}/budget-summary");

        $summary->assertOk()
            ->assertJsonPath('data.actual_cost', 400000);
    }
}
