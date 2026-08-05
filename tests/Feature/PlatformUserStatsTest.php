<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformUserStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function admin(array $overrides = []): User
    {
        $admin = User::factory()->create(array_merge([
            'is_active' => true,
            'status' => 'active',
            'last_login_at' => now(),
        ], $overrides));
        $admin->assignRole('platform-admin');

        return $admin;
    }

    private function userWithBusiness(array $overrides = []): User
    {
        $owner = User::factory()->create(array_merge(['is_active' => true], $overrides));
        $business = Business::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);
        $owner->business_id = $business->id;
        $owner->save();

        return $owner;
    }

    public function test_requires_platform_users_view_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/platform/users/stats')
            ->assertForbidden();
    }

    public function test_returns_platform_wide_onboarding_totals(): void
    {
        $admin = $this->admin();
        $now = now();

        $activeUser = $this->userWithBusiness(['created_at' => $now, 'status' => 'active']);
        $this->userWithBusiness(['created_at' => $now, 'status' => 'deactivated']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/users/stats?date_from='.now()->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'onboarding' => ['today', 'this_week', 'this_month', 'in_range', 'range_from', 'range_to'],
                    'totals' => ['total', 'active', 'warning', 'notified', 'restricted', 'deactivated', 'with_business', 'platform_admins', 'logins_30d'],
                    'growth' => [['date', 'signups', 'cumulative']],
                    'decisions',
                ],
            ]);

        $data = $response->json('data');

        $this->assertSame(3, $data['totals']['total']);
        $this->assertSame(2, $data['totals']['active']);
        $this->assertSame(1, $data['totals']['deactivated']);
        $this->assertSame(2, $data['totals']['with_business']);
        $this->assertSame(1, $data['totals']['platform_admins']);
        $this->assertGreaterThanOrEqual(1, $data['totals']['logins_30d']);
        $this->assertGreaterThanOrEqual(1, count($data['growth']));
    }

    public function test_respects_date_range_window(): void
    {
        $admin = $this->admin(['created_at' => now()->subDays(90)]);

        $this->userWithBusiness(['created_at' => now()->subDays(60)]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/users/stats?date_from='.now()->toDateString().'&date_to='.now()->toDateString())
            ->assertOk();

        $this->assertSame(0, $response->json('data.onboarding.in_range'));
        $this->assertSame(now()->toDateString(), $response->json('data.onboarding.range_from'));
        $this->assertSame(now()->toDateString(), $response->json('data.onboarding.range_to'));
    }

    public function test_filters_users_by_status_server_side(): void
    {
        $admin = $this->admin();
        $this->userWithBusiness(['status' => 'active']);
        $deactivatedUser = $this->userWithBusiness(['status' => 'deactivated']);

        $activeResponse = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/users?status=active&per_page=15')
            ->assertOk();

        $deactivatedResponse = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/users?status=deactivated&per_page=15')
            ->assertOk();

        $activeIds = collect($activeResponse->json('data'))->pluck('id')->all();
        $this->assertContains($admin->id, $activeIds);
        $this->assertNotContains($deactivatedUser->id, $activeIds);

        $deactivatedIds = collect($deactivatedResponse->json('data'))->pluck('id')->all();
        $this->assertContains($deactivatedUser->id, $deactivatedIds);
        $this->assertNotContains($admin->id, $deactivatedIds);
    }

    public function test_filters_users_by_login_activity_server_side(): void
    {
        $admin = $this->admin();
        $this->userWithBusiness(['last_login_at' => now()]);
        $activeUser = $this->userWithBusiness(['last_login_at' => null]);

        $activeResponse = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/users?login_activity=active&per_page=15')
            ->assertOk();

        $neverResponse = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/users?login_activity=never_logged_in&per_page=15')
            ->assertOk();

        $activeIds = collect($activeResponse->json('data'))->pluck('id')->all();
        $this->assertNotContains($activeUser->id, $activeIds);
        $this->assertContains($admin->id, $activeIds);

        $neverIds = collect($neverResponse->json('data'))->pluck('id')->all();
        $this->assertContains($activeUser->id, $neverIds);
        $this->assertNull($neverResponse->json('data.0.last_login_at'));
    }

    public function test_filters_users_by_business_linkage_server_side(): void
    {
        $admin = $this->admin();
        $this->userWithBusiness();
        $orphan = User::factory()->create(['is_active' => true]);

        $noBusinessResponse = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/users?business=no_business&per_page=15')
            ->assertOk();

        $ids = collect($noBusinessResponse->json('data'))->pluck('id')->all();
        $this->assertContains($orphan->id, $ids);
    }

    public function test_update_status_syncs_status_column_and_timestamp(): void
    {
        [$owner] = [$this->userWithBusiness(['status' => 'active'])];
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/users/'.$owner->id.'/status', [
                'is_active' => false,
                'reason' => 'Test deactivation',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $owner->id,
            'status' => 'deactivated',
        ]);

        $fresh = $owner->fresh();
        $this->assertNotNull($fresh->status_changed_at);
        $this->assertFalse((bool) $fresh->is_active);
    }

    public function test_stats_include_growth_curve_for_range(): void
    {
        $admin = $this->admin(['created_at' => now()->subDays(30)]);

        $recentUser = $this->userWithBusiness(['created_at' => now()->subDays(2)]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/users/stats?date_from='.now()->subDays(5)->toDateString().'&date_to='.now()->toDateString())
            ->assertOk();

        $growth = $response->json('data.growth');
        $this->assertCount(6, $growth);

        $this->assertSame(now()->subDays(5)->toDateString(), $growth[0]['date']);
        $this->assertSame(0, $growth[0]['signups']);

        $recentDay = collect($growth)->firstWhere(
            'date',
            $recentUser->created_at->toDateString(),
        );
        $this->assertNotNull($recentDay);
        $this->assertSame(1, $recentDay['signups']);
        $this->assertGreaterThanOrEqual($growth[0]['cumulative'], $growth[5]['cumulative']);
    }

    public function test_filters_users_by_platform_admin_server_side(): void
    {
        $admin = $this->admin();
        $this->userWithBusiness();
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('platform-admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/users?business=platform_admin&per_page=15')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($admin->id, $ids);
        $this->assertContains($other->id, $ids);
    }
}
