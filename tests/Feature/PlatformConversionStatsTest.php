<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformConversionStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'status' => 'active',
        ]);
        $admin->assignRole('platform-admin');

        return $admin;
    }

    private function createTrial(string $planSlug = 'enterprise', ?string $convertedAt = null, ?string $createdAt = null): Subscription
    {
        $plan = Plan::where('slug', $planSlug)->firstOrFail();
        $owner = User::factory()->create(['is_active' => true]);
        $business = Business::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);
        $owner->business_id = $business->id;
        $owner->save();

        $subscription = Subscription::create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'price_monthly_usd' => $plan->price_monthly_usd,
            'price_yearly_usd' => $plan->price_yearly_usd,
            'onboarding_fee_usd' => $plan->onboarding_fee_usd ?? 0,
            'billing_cycle' => 'monthly',
            'status' => $convertedAt ? 'active' : 'trial',
            'starts_at' => $createdAt ?? now(),
            'converted_at' => $convertedAt ? now()->parse($convertedAt) : null,
        ]);

        if ($createdAt !== null) {
            $subscription->created_at = now()->parse($createdAt);
            $subscription->updated_at = now()->parse($createdAt);
            $subscription->save();
        }

        return $subscription->refresh();
    }

    public function test_requires_platform_conversions_view_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/platform/conversions')
            ->assertForbidden();
    }

    public function test_returns_conversion_summary_and_series(): void
    {
        $admin = $this->admin();

        $this->createTrial('enterprise', convertedAt: now()->subDays(1)->toDateTimeString(), createdAt: now()->subDays(5)->toDateTimeString());
        $this->createTrial('enterprise', convertedAt: now()->subDays(10)->toDateTimeString(), createdAt: now()->subDays(15)->toDateTimeString());
        $this->createTrial('essential', convertedAt: null, createdAt: now()->subDays(3)->toDateTimeString());

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/conversions?date_from='.now()->subDays(29)->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'summary' => [
                        'trials_started' => ['today', 'this_week', 'this_month', 'in_range'],
                        'converted' => ['today', 'this_week', 'this_month', 'in_range'],
                        'conversion_rate',
                        'status_now' => ['active', 'on_trial', 'past_due', 'cancelled', 'suspended'],
                        'range_from',
                        'range_to',
                    ],
                    'monthly',
                    'by_plan',
                    'decisions',
                ],
            ]);

        $data = $response->json('data');

        $this->assertSame(3, $data['summary']['trials_started']['in_range']);
        $this->assertSame(2, $data['summary']['converted']['in_range']);
        $this->assertSame(66.7, $data['summary']['conversion_rate']);
        $this->assertSame(2, $data['summary']['status_now']['active']);
        $this->assertSame(1, $data['summary']['status_now']['on_trial']);

        $this->assertCount(12, $data['monthly']);
        $this->assertArrayHasKey('trials_started', $data['monthly'][0]);
        $this->assertArrayHasKey('converted', $data['monthly'][0]);
        $this->assertArrayHasKey('conversion_rate', $data['monthly'][0]);

        $this->assertNotEmpty($data['by_plan']);
        $enterprise = collect($data['by_plan'])->firstWhere('plan_slug', 'enterprise');
        $this->assertSame(2, $enterprise['trials_started']);
        $this->assertSame(2, $enterprise['converted']);
    }

    public function test_respects_date_range_window(): void
    {
        $admin = $this->admin(['created_at' => now()->subDays(90)]);

        $this->createTrial('enterprise', convertedAt: null, createdAt: now()->subDays(60)->toDateTimeString());

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/conversions?date_from='.now()->subDays(7)->toDateString().'&date_to='.now()->toDateString())
            ->assertOk();

        $this->assertSame(0, $response->json('data.summary.trials_started.in_range'));
        $this->assertSame(0, $response->json('data.summary.converted.in_range'));
        $this->assertSame(now()->subDays(7)->toDateString(), $response->json('data.summary.range_from'));
    }

    public function test_zero_division_is_safe(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/conversions')
            ->assertOk();

        $this->assertSame(0.0, (float) $response->json('data.summary.conversion_rate'));
        $this->assertSame(0.0, (float) $response->json('data.monthly.0.conversion_rate'));
    }
}
