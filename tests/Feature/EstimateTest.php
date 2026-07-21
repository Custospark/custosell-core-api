<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\EstimateVersion;
use App\Models\User;
use App\Services\ModuleAccessService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsAccounting;
use Tests\TestCase;

class EstimateTest extends TestCase
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

        $this->user = User::factory()->create([
            'is_active' => true,
            'modules' => [ModuleAccessService::ESTIMATES_FULL_SLUG],
        ]);
        $this->token = $this->user->createToken('owner')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->user->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->user->update(['business_id' => $this->business->id]);

        $this->seedAccountingForBusiness($this->business);
    }

    public function test_create_estimate_with_line_items(): void
    {
        $customer = Customer::factory()->create(['business_id' => $this->business->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/estimates', [
                'customer_id' => $customer->id,
                'title' => 'Website redesign',
                'tax_rate' => 18,
                'line_items' => [
                    [
                        'description' => 'Design work',
                        'quantity' => 10,
                        'unit_cost' => 50000,
                        'markup_type' => 'percent',
                        'markup_value' => 20,
                        'type' => 'labor',
                    ],
                    [
                        'description' => 'Hosting setup',
                        'quantity' => 1,
                        'unit_cost' => 100000,
                        'markup_type' => 'fixed',
                        'markup_value' => 50000,
                        'type' => 'other',
                    ],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('title', 'Website redesign')
            ->assertJsonPath('status', 'draft')
            ->assertJsonCount(2, 'line_items');

        $this->assertDatabaseHas('estimates', [
            'business_id' => $this->business->id,
            'title' => 'Website redesign',
            'status' => 'draft',
        ]);

        $this->assertDatabaseCount('estimate_line_items', 2);
    }

    public function test_line_item_margin_calculation(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/estimates', [
                'title' => 'Margin test',
                'tax_rate' => 0,
                'line_items' => [
                    [
                        'description' => 'Item with 50% markup',
                        'quantity' => 2,
                        'unit_cost' => 100,
                        'markup_type' => 'percent',
                        'markup_value' => 50,
                    ],
                ],
            ]);

        $response->assertCreated();

        $data = $response->json();
        $this->assertEquals(300.0, (float) $data['subtotal']);
        $this->assertEquals(200.0, (float) $data['cost_subtotal']);
        $this->assertEquals(100.0, (float) $data['gross_profit']);
        $this->assertEquals(33.33, (float) $data['margin_percent']);

        $lineItem = $data['line_items'][0];
        $this->assertEquals(150.0, (float) $lineItem['unit_price']);
        $this->assertEquals(300.0, (float) $lineItem['total_price']);
        $this->assertEquals(200.0, (float) $lineItem['total_cost']);
    }

    public function test_send_creates_version_snapshot(): void
    {
        $create = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/estimates', [
                'title' => 'Versioned estimate',
                'line_items' => [
                    [
                        'description' => 'Consulting',
                        'quantity' => 1,
                        'unit_cost' => 1000,
                        'markup_type' => 'none',
                        'unit_price' => 1500,
                    ],
                ],
            ]);

        $create->assertCreated();
        $estimateId = $create->json('id');

        $send = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/estimates/{$estimateId}/send", [
                'change_summary' => 'Initial send',
            ]);

        $send->assertOk()
            ->assertJsonPath('status', 'sent');

        $this->assertDatabaseHas('estimate_versions', [
            'estimate_id' => $estimateId,
            'version' => 1,
            'change_summary' => 'Initial send',
        ]);

        $version = EstimateVersion::query()->where('estimate_id', $estimateId)->first();
        $this->assertNotNull($version);
        $this->assertArrayHasKey('estimate', $version->snapshot);
        $this->assertArrayHasKey('line_items', $version->snapshot);
    }

    public function test_convert_to_invoice(): void
    {
        $customer = Customer::factory()->create(['business_id' => $this->business->id]);

        $create = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/estimates', [
                'customer_id' => $customer->id,
                'title' => 'Billable project',
                'tax_rate' => 0,
                'line_items' => [
                    [
                        'description' => 'Development',
                        'quantity' => 1,
                        'unit_cost' => 500,
                        'markup_type' => 'none',
                        'unit_price' => 1000,
                        'is_billable' => true,
                    ],
                ],
            ]);

        $estimateId = $create->json('id');

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/estimates/{$estimateId}/send")
            ->assertOk();

        $convert = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/estimates/{$estimateId}/convert-to-invoice", [
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
            ]);

        $convert->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('total_amount', '1000.00');

        $estimate = Estimate::findOrFail($estimateId);
        $this->assertNotNull($estimate->invoice_id);
        $this->assertEquals('converted', $estimate->status);

        $this->assertDatabaseHas('invoices', [
            'id' => $estimate->invoice_id,
            'estimate_id' => $estimateId,
            'customer_id' => $customer->id,
        ]);
    }
}
