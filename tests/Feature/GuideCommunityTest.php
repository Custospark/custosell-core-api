<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\GuideCommunity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuideCommunityTest extends TestCase
{
    use RefreshDatabase;

    protected function platformAdmin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('platform-admin');

        return $admin;
    }

    public function test_requires_platform_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/platform/guide/communities')
            ->assertStatus(403);
    }

    public function test_platform_admin_can_create_and_list_communities(): void
    {
        $admin = $this->platformAdmin();

        $create = $this->actingAs($admin)
            ->postJson('/api/v1/platform/guide/communities', [
                'name' => 'Custosell WhatsApp',
                'description' => 'Ask questions and share tips.',
                'platform' => 'whatsapp',
                'url' => 'https://chat.whatsapp.com/EXAMPLE',
                'sort_order' => 1,
                'is_published' => true,
            ]);
        $create->assertStatus(201)
            ->assertJsonPath('data.name', 'Custosell WhatsApp')
            ->assertJsonPath('data.platform', 'whatsapp');

        $list = $this->actingAs($admin)
            ->getJson('/api/v1/platform/guide/communities');
        $list->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Custosell WhatsApp');
    }

    public function test_platform_admin_can_update_and_archive(): void
    {
        $admin = $this->platformAdmin();
        $community = GuideCommunity::create([
            'name' => 'Telegram',
            'platform' => 'telegram',
            'url' => 'https://t.me/custosell',
            'is_published' => true,
            'created_by' => $admin->id,
        ]);

        $update = $this->actingAs($admin)
            ->putJson("/api/v1/platform/guide/communities/{$community->id}", [
                'name' => 'Custosell Telegram',
                'platform' => 'telegram',
                'url' => 'https://t.me/custosell_new',
                'is_published' => false,
            ]);
        $update->assertStatus(200)
            ->assertJsonPath('data.name', 'Custosell Telegram')
            ->assertJsonPath('data.is_published', false);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/platform/guide/communities/{$community->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('guide_communities', ['id' => $community->id]);
    }

    public function test_authenticated_user_sees_only_published_communities(): void
    {
        $admin = $this->platformAdmin();

        $business = Business::factory()->create(['owner_id' => $admin->id, 'status' => 'active']);
        $user = User::factory()->create(['is_active' => true, 'business_id' => $business->id]);

        GuideCommunity::create(['name' => 'Published', 'platform' => 'whatsapp', 'url' => 'https://chat.whatsapp.com/A', 'is_published' => true, 'sort_order' => 1, 'created_by' => $admin->id]);
        GuideCommunity::create(['name' => 'Hidden', 'platform' => 'telegram', 'url' => 'https://t.me/x', 'is_published' => false, 'sort_order' => 2, 'created_by' => $admin->id]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/guide/communities');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Published');
    }

    public function test_guest_cannot_read_communities(): void
    {
        $this->getJson('/api/v1/guide/communities')->assertStatus(401);
    }
}