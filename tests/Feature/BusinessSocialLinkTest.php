<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessSocialLink;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessSocialLinkTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $staff;
    protected User $otherOwner;
    protected Business $business;
    protected Business $otherBusiness;
    protected string $ownerToken;
    protected string $staffToken;
    protected string $otherOwnerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create([
            'owner_id' => $this->owner->id,
            'currency' => 'UGX',
            'status' => 'active',
            'storefront_enabled' => true,
        ]);
        $this->owner->business_id = $this->business->id;
        $this->owner->save();

        $this->grantRole($this->owner, 'Admin', [
            'settings.view' => true, 'settings.edit' => true,
        ]);
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $this->staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
        ]);
        $this->grantRole($this->staff, 'Staff', [
            'settings.view' => true, 'settings.edit' => true,
        ]);
        $this->staffToken = $this->staff->createToken('staff')->plainTextToken;

        $this->otherOwner = User::factory()->create(['is_active' => true]);
        $this->otherBusiness = Business::factory()->create([
            'owner_id' => $this->otherOwner->id,
            'currency' => 'UGX',
            'status' => 'active',
            'storefront_enabled' => true,
        ]);
        $this->otherOwner->business_id = $this->otherBusiness->id;
        $this->otherOwner->save();
        $this->grantRole($this->otherOwner, 'Admin', [
            'settings.view' => true, 'settings.edit' => true,
        ]);
        $this->otherOwnerToken = $this->otherOwner->createToken('owner')->plainTextToken;
    }

    protected function grantRole(User $user, string $name, array $permissions): void
    {
        $role = Role::create([
            'business_id' => $user->business_id,
            'name' => $name,
            'slug' => strtolower($name),
            'permissions' => $permissions,
        ]);
        $user->role_id = $role->id;
        $user->save();
    }

    public function test_owner_can_list_social_links(): void
    {
        BusinessSocialLink::factory()->count(2)->create([
            'business_id' => $this->business->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->getJson('/api/v1/business-social-links');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_owner_can_create_social_link(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->postJson('/api/v1/business-social-links', [
                'platform' => 'Facebook',
                'url' => 'https://facebook.com/my-business',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'business_id', 'platform', 'url']])
            ->assertJsonPath('data.platform', 'facebook')
            ->assertJsonPath('data.business_id', $this->business->id);
    }

    public function test_owner_can_create_custom_platform(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->postJson('/api/v1/business-social-links', [
                'platform' => 'Threads',
                'url' => 'https://threads.net/@my-business',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.platform', 'threads');
    }

    public function test_duplicate_platform_upserts_instead_of_duplicating(): void
    {
        $first = $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->postJson('/api/v1/business-social-links', [
                'platform' => 'Instagram',
                'url' => 'https://instagram.com/first',
            ])->json('data');

        $second = $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->postJson('/api/v1/business-social-links', [
                'platform' => 'instagram',
                'url' => 'https://instagram.com/second',
            ])->json('data');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame('https://instagram.com/second', $second['url']);
        $this->assertDatabaseCount('business_social_links', 1);
    }

    public function test_invalid_url_is_rejected(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->postJson('/api/v1/business-social-links', [
                'platform' => 'Facebook',
                'url' => 'not-a-url',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('url');
    }

    public function test_missing_platform_is_rejected(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->postJson('/api/v1/business-social-links', [
                'url' => 'https://facebook.com/my-business',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('platform');
    }

    public function test_owner_can_update_social_link(): void
    {
        $link = BusinessSocialLink::factory()->create([
            'business_id' => $this->business->id,
            'platform' => 'facebook',
            'url' => 'https://facebook.com/old',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->putJson("/api/v1/business-social-links/{$link->id}", [
                'platform' => 'Facebook',
                'url' => 'https://facebook.com/new',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.url', 'https://facebook.com/new');
    }

    public function test_owner_can_rename_platform_of_social_link(): void
    {
        $link = BusinessSocialLink::factory()->create([
            'business_id' => $this->business->id,
            'platform' => 'facebook',
            'url' => 'https://facebook.com/my-business',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->putJson("/api/v1/business-social-links/{$link->id}", [
                'platform' => 'Snapchat',
                'url' => 'https://snapchat.com/add/my-business',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.platform', 'snapchat')
            ->assertJsonPath('data.url', 'https://snapchat.com/add/my-business');
        $this->assertDatabaseHas('business_social_links', [
            'id' => $link->id,
            'platform' => 'snapchat',
        ]);
    }

    public function test_rename_to_existing_platform_returns_422(): void
    {
        BusinessSocialLink::factory()->create([
            'business_id' => $this->business->id,
            'platform' => 'facebook',
            'url' => 'https://facebook.com/a',
        ]);
        $target = BusinessSocialLink::factory()->create([
            'business_id' => $this->business->id,
            'platform' => 'tiktok',
            'url' => 'https://tiktok.com/@b',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->putJson("/api/v1/business-social-links/{$target->id}", [
                'platform' => 'facebook',
                'url' => 'https://facebook.com/moved',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('business_social_links', [
            'id' => $target->id,
            'platform' => 'tiktok',
        ]);
    }

    public function test_owner_can_delete_social_link(): void
    {
        $link = BusinessSocialLink::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->deleteJson("/api/v1/business-social-links/{$link->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('business_social_links', ['id' => $link->id]);
    }

    public function test_non_owner_staff_is_forbidden(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->postJson('/api/v1/business-social-links', [
                'platform' => 'Facebook',
                'url' => 'https://facebook.com/my-business',
            ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/business-social-links')
            ->assertStatus(401);
    }

    public function test_cross_business_access_is_hidden(): void
    {
        $foreignLink = BusinessSocialLink::factory()->create([
            'business_id' => $this->otherBusiness->id,
            'platform' => 'facebook',
            'url' => 'https://facebook.com/other',
        ]);

        $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->getJson("/api/v1/business-social-links/{$foreignLink->id}")
            ->assertStatus(404);

        $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->putJson("/api/v1/business-social-links/{$foreignLink->id}", [
                'platform' => 'facebook',
                'url' => 'https://facebook.com/hacked',
            ])->assertStatus(404);

        $this->withHeader('Authorization', "Bearer $this->ownerToken")
            ->deleteJson("/api/v1/business-social-links/{$foreignLink->id}")
            ->assertStatus(404);
    }

    public function test_social_links_appear_in_public_storefront(): void
    {
        BusinessSocialLink::factory()->create([
            'business_id' => $this->business->id,
            'platform' => 'facebook',
            'url' => 'https://facebook.com/my-business',
            'sort_order' => 0,
        ]);
        BusinessSocialLink::factory()->create([
            'business_id' => $this->business->id,
            'platform' => 'tiktok',
            'url' => 'https://tiktok.com/@my-business',
            'sort_order' => 1,
        ]);

        $response = $this->getJson("/api/v1/storefront/{$this->business->slug}");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'social_links')
            ->assertJsonPath('social_links.0.platform', 'facebook')
            ->assertJsonPath('social_links.0.url', 'https://facebook.com/my-business')
            ->assertJsonPath('social_links.1.platform', 'tiktok');
    }
}