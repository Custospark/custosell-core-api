<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentsModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->seed(SystemRoleSeeder::class);
        Storage::fake('public');

        $this->owner = User::factory()->create([
            'is_active' => true,
            'modules' => ['documents', 'settings'],
        ]);

        $this->business = Business::factory()->create([
            'owner_id' => $this->owner->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);

        $this->owner->update(['business_id' => $this->business->id]);
    }

    public function test_owner_can_create_folder_and_upload_document(): void
    {
        $token = $this->owner->createToken('owner')->plainTextToken;

        $folderResponse = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/documents/folders', [
                'name' => 'HR',
                'visibility' => 'all_staff',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'HR');

        $folderId = (int) $folderResponse->json('data.id');

        $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/documents/upload', [
                'file' => UploadedFile::fake()->create('policy.pdf', 100, 'application/pdf'),
                'folder_id' => $folderId,
                'title' => 'Employee Policy',
                'visibility' => 'inherit',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Employee Policy')
            ->assertJsonPath('data.can_view', true);
    }

    public function test_staff_without_module_cannot_access_documents(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['sales'],
        ]);
        $token = $staff->createToken('staff')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/documents/folders/tree')
            ->assertStatus(403);
    }

    public function test_staff_with_module_can_create_root_folder_and_link(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['documents'],
        ]);
        $token = $staff->createToken('staff')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/documents/folders', [
                'name' => 'Shared',
                'visibility' => 'all_staff',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Shared');

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/documents/link', [
                'title' => 'Company site',
                'url' => 'https://example.com',
                'visibility' => 'all_staff',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Company site');
    }

    public function test_root_folder_rejects_inherit_visibility(): void
    {
        $token = $this->owner->createToken('owner')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/documents/folders', [
                'name' => 'Bad root',
                'visibility' => 'inherit',
            ])
            ->assertStatus(422);
    }

    public function test_live_inheritance_grants_access_after_folder_update(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['documents'],
        ]);

        $folder = DocumentFolder::create([
            'business_id' => $this->business->id,
            'name' => 'Private',
            'visibility' => 'owner_only',
            'depth' => 1,
            'created_by' => $this->owner->id,
        ]);

        $document = Document::create([
            'business_id' => $this->business->id,
            'folder_id' => $folder->id,
            'type' => 'link',
            'title' => 'Secret',
            'visibility' => 'inherit',
            'url' => 'https://example.com',
            'uploaded_by' => $this->owner->id,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/documents/{$document->id}")
            ->assertStatus(403);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/documents/folders/{$folder->id}", [
                'visibility' => 'all_staff',
            ])
            ->assertOk();

        $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.can_contribute', true);
    }

    public function test_documents_list_is_paginated(): void
    {
        $token = $this->owner->createToken('owner')->plainTextToken;

        for ($i = 1; $i <= 3; $i++) {
            Document::create([
                'business_id' => $this->business->id,
                'folder_id' => null,
                'type' => 'link',
                'title' => "Doc {$i}",
                'visibility' => 'all_staff',
                'url' => "https://example.com/{$i}",
                'uploaded_by' => $this->owner->id,
            ]);
        }

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/documents?per_page=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_documents_list_accepts_root_only_query_flag(): void
    {
        $token = $this->owner->createToken('owner')->plainTextToken;

        $folder = DocumentFolder::create([
            'business_id' => $this->business->id,
            'name' => 'Nested',
            'visibility' => 'all_staff',
            'depth' => 1,
            'created_by' => $this->owner->id,
        ]);

        Document::create([
            'business_id' => $this->business->id,
            'folder_id' => null,
            'type' => 'link',
            'title' => 'Root Doc',
            'visibility' => 'all_staff',
            'url' => 'https://example.com/root',
            'uploaded_by' => $this->owner->id,
        ]);

        Document::create([
            'business_id' => $this->business->id,
            'folder_id' => $folder->id,
            'type' => 'link',
            'title' => 'Nested Doc',
            'visibility' => 'inherit',
            'url' => 'https://example.com/nested',
            'uploaded_by' => $this->owner->id,
        ]);

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/documents?root_only=true&per_page=100&page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Root Doc');
    }

    public function test_folder_contents_returns_documents_in_folder(): void
    {
        $token = $this->owner->createToken('owner')->plainTextToken;

        $folder = DocumentFolder::create([
            'business_id' => $this->business->id,
            'name' => 'Projects',
            'visibility' => 'all_staff',
            'depth' => 1,
            'created_by' => $this->owner->id,
        ]);

        Document::create([
            'business_id' => $this->business->id,
            'folder_id' => $folder->id,
            'type' => 'link',
            'title' => 'Brief',
            'visibility' => 'inherit',
            'url' => 'https://example.com/brief',
            'uploaded_by' => $this->owner->id,
        ]);

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/documents/folders/{$folder->id}/contents?page=1")
            ->assertOk()
            ->assertJsonPath('data.folder.name', 'Projects')
            ->assertJsonCount(1, 'data.documents')
            ->assertJsonPath('data.documents.0.title', 'Brief');
    }

    public function test_accessible_members_returns_all_active_business_staff(): void
    {
        $salesStaff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['sales'],
            'name' => 'Sales Rep',
        ]);

        $inactiveStaff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => false,
            'modules' => ['documents'],
            'name' => 'Inactive User',
        ]);

        $token = $this->owner->createToken('owner')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/documents/accessible-members')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertContains('Sales Rep', $names);
        $this->assertContains($this->owner->name, $names);
        $this->assertNotContains('Inactive User', $names);
    }

    public function test_activity_feed_returns_recent_events(): void
    {
        $token = $this->owner->createToken('owner')->plainTextToken;

        $folder = DocumentFolder::create([
            'business_id' => $this->business->id,
            'name' => 'Legal',
            'visibility' => 'all_staff',
            'depth' => 1,
            'created_by' => $this->owner->id,
        ]);

        app(\App\Services\Documents\DocumentActivityService::class)->record(
            $this->business->id,
            $this->owner,
            'folder_created',
            'folder',
            $folder->id,
            $folder->name,
            null,
        );

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/documents/activity')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'folder_created')
            ->assertJsonPath('data.0.subject_name', 'Legal');
    }
}
