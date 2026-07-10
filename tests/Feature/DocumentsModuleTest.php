<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Document;
use App\Models\DocumentCabinet;
use App\Models\DocumentFolder;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentsModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected int $cabinetId;

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

        $this->cabinetId = (int) DocumentCabinet::query()
            ->where('business_id', $this->business->id)
            ->where('name', 'General')
            ->value('id');
    }

    protected function folderAttributes(array $overrides = []): array
    {
        return array_merge([
            'business_id' => $this->business->id,
            'cabinet_id' => $this->cabinetId,
            'created_by' => $this->owner->id,
        ], $overrides);
    }

    protected function documentAttributes(array $overrides = []): array
    {
        return array_merge([
            'business_id' => $this->business->id,
            'cabinet_id' => $this->cabinetId,
            'uploaded_by' => $this->owner->id,
        ], $overrides);
    }

    public function test_owner_can_create_folder_and_upload_document(): void
    {
        $token = $this->owner->createToken('owner')->plainTextToken;

        $folderResponse = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/documents/folders', [
                'name' => 'HR',
                'visibility' => 'all_staff',
                'cabinet_id' => $this->cabinetId,
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
                'cabinet_id' => $this->cabinetId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Shared');

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/documents/link', [
                'title' => 'Company site',
                'url' => 'https://example.com',
                'visibility' => 'all_staff',
                'cabinet_id' => $this->cabinetId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Company site');
    }

    public function test_root_folder_can_inherit_cabinet_visibility(): void
    {
        $token = $this->owner->createToken('owner')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/documents/folders', [
                'name' => 'Inherited root',
                'visibility' => 'inherit',
                'cabinet_id' => $this->cabinetId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.visibility', 'inherit');
    }

    public function test_folder_children_requires_cabinet_id(): void
    {
        $token = $this->owner->createToken('owner')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/documents/folders/children')
            ->assertStatus(422);

        DocumentFolder::create($this->folderAttributes([
            'name' => 'Root folder',
            'visibility' => 'all_staff',
            'depth' => 1,
        ]));

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/documents/folders/children?cabinet_id={$this->cabinetId}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Root folder');
    }

    public function test_owner_can_create_and_list_cabinets(): void
    {
        $token = $this->owner->createToken('owner')->plainTextToken;

        $create = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/documents/cabinets', [
                'name' => 'Finance',
                'visibility' => 'all_staff',
                'description' => 'Finance docs',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Finance');

        $cabinetId = (int) $create->json('data.id');

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/documents/cabinets')
            ->assertOk()
            ->assertJsonFragment(['name' => 'General'])
            ->assertJsonFragment(['name' => 'Finance']);

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/documents/cabinets/{$cabinetId}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Finance');
    }

    public function test_cannot_delete_cabinet_with_contents(): void
    {
        $token = $this->owner->createToken('owner')->plainTextToken;

        DocumentFolder::create($this->folderAttributes([
            'name' => 'Blocked',
            'visibility' => 'all_staff',
            'depth' => 1,
        ]));

        $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/v1/documents/cabinets/{$this->cabinetId}")
            ->assertStatus(422);
    }

    public function test_live_inheritance_grants_access_after_folder_update(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['documents'],
        ]);

        $folder = DocumentFolder::create($this->folderAttributes([
            'name' => 'Private',
            'visibility' => 'owner_only',
            'depth' => 1,
        ]));

        $document = Document::create($this->documentAttributes([
            'folder_id' => $folder->id,
            'type' => 'link',
            'title' => 'Secret',
            'visibility' => 'inherit',
            'url' => 'https://example.com',
        ]));

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
            Document::create($this->documentAttributes([
                'folder_id' => null,
                'type' => 'link',
                'title' => "Doc {$i}",
                'visibility' => 'all_staff',
                'url' => "https://example.com/{$i}",
            ]));
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

        $folder = DocumentFolder::create($this->folderAttributes([
            'name' => 'Nested',
            'visibility' => 'all_staff',
            'depth' => 1,
        ]));

        Document::create($this->documentAttributes([
            'folder_id' => null,
            'type' => 'link',
            'title' => 'Root Doc',
            'visibility' => 'all_staff',
            'url' => 'https://example.com/root',
        ]));

        Document::create($this->documentAttributes([
            'folder_id' => $folder->id,
            'type' => 'link',
            'title' => 'Nested Doc',
            'visibility' => 'inherit',
            'url' => 'https://example.com/nested',
        ]));

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/documents?root_only=true&cabinet_id={$this->cabinetId}&per_page=100&page=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Root Doc');
    }

    public function test_folder_contents_returns_documents_in_folder(): void
    {
        $token = $this->owner->createToken('owner')->plainTextToken;

        $folder = DocumentFolder::create($this->folderAttributes([
            'name' => 'Projects',
            'visibility' => 'all_staff',
            'depth' => 1,
        ]));

        Document::create($this->documentAttributes([
            'folder_id' => $folder->id,
            'type' => 'link',
            'title' => 'Brief',
            'visibility' => 'inherit',
            'url' => 'https://example.com/brief',
        ]));

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

        User::factory()->create([
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

        $folder = DocumentFolder::create($this->folderAttributes([
            'name' => 'Legal',
            'visibility' => 'all_staff',
            'depth' => 1,
        ]));

        app(\App\Services\Documents\DocumentActivityService::class)->record(
            $this->business->id,
            $this->owner,
            'folder_created',
            'folder',
            $folder->id,
            $folder->name,
            null,
            $folder->cabinet_id,
        );

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/documents/activity?cabinet_id='.$this->cabinetId)
            ->assertOk()
            ->assertJsonPath('data.0.action', 'folder_created')
            ->assertJsonPath('data.0.subject_name', 'Legal');
    }

    public function test_owner_can_delete_document_and_folder(): void
    {
        $token = $this->owner->createToken('owner')->plainTextToken;

        $folder = DocumentFolder::create($this->folderAttributes([
            'name' => 'Archive',
            'visibility' => 'all_staff',
            'depth' => 1,
        ]));

        $upload = $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/documents/upload', [
                'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
                'folder_id' => $folder->id,
                'title' => 'Notes',
                'visibility' => 'inherit',
            ])
            ->assertCreated();

        $documentId = (int) $upload->json('data.id');

        $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/v1/documents/{$documentId}")
            ->assertOk();

        $this->assertSoftDeleted('documents', ['id' => $documentId]);

        $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/v1/documents/folders/{$folder->id}")
            ->assertOk();

        $this->assertSoftDeleted('document_folders', ['id' => $folder->id]);
    }

    public function test_owner_can_export_folder_as_zip(): void
    {
        $token = $this->owner->createToken('owner')->plainTextToken;

        $folder = DocumentFolder::create($this->folderAttributes([
            'name' => 'Exports',
            'visibility' => 'all_staff',
            'depth' => 1,
        ]));

        $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/documents/upload', [
                'file' => UploadedFile::fake()->create('readme.txt', 10, 'text/plain'),
                'folder_id' => $folder->id,
                'title' => 'Readme',
                'visibility' => 'inherit',
            ])
            ->assertCreated();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->get("/api/v1/documents/folders/{$folder->id}/export");

        $response->assertOk();
        $this->assertStringContainsString('application/zip', (string) $response->headers->get('content-type'));
        $this->assertNotSame('', $response->getContent());
    }

    public function test_owner_can_email_document_and_folder(): void
    {
        Mail::fake();

        $token = $this->owner->createToken('owner')->plainTextToken;

        $folder = DocumentFolder::create($this->folderAttributes([
            'name' => 'Shareable',
            'visibility' => 'all_staff',
            'depth' => 1,
        ]));

        $upload = $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/documents/upload', [
                'file' => UploadedFile::fake()->create('share.txt', 10, 'text/plain'),
                'folder_id' => $folder->id,
                'title' => 'Share me',
                'visibility' => 'inherit',
            ])
            ->assertCreated();

        $documentId = (int) $upload->json('data.id');

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/documents/{$documentId}/email", [
                'to' => 'staff@example.com',
                'message' => 'Please review',
            ])
            ->assertOk()
            ->assertJsonPath('sent_to', 'staff@example.com');

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/documents/folders/{$folder->id}/email", [
                'to' => 'external@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('sent_to', 'external@example.com');

        Mail::assertSent(\App\Mail\CustomerDocumentEmail::class, 2);
    }

    public function test_audio_upload_rejects_files_over_ten_mb(): void
    {
        config(['documents.max_media_file_size_kb' => 10240]);
        $token = $this->owner->createToken('owner')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/documents/upload', [
                'file' => UploadedFile::fake()->create('clip.mp3', 11000, 'audio/mpeg'),
                'title' => 'Too large',
                'visibility' => 'all_staff',
                'cabinet_id' => $this->cabinetId,
            ])
            ->assertStatus(422);
    }

    public function test_owner_can_view_and_edit_text_file_content(): void
    {
        $token = $this->owner->createToken('owner')->plainTextToken;

        $upload = $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/documents/upload', [
                'file' => UploadedFile::fake()->createWithContent('notes.txt', "Hello world\n"),
                'title' => 'Notes',
                'visibility' => 'all_staff',
                'cabinet_id' => $this->cabinetId,
            ])
            ->assertCreated();

        $documentId = (int) $upload->json('data.id');

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/documents/{$documentId}/content")
            ->assertOk()
            ->assertJsonPath('data.content', "Hello world\n")
            ->assertJsonPath('data.content_type', 'text')
            ->assertJsonPath('data.editable', true);

        $this->withHeader('Authorization', "Bearer $token")
            ->putJson("/api/v1/documents/{$documentId}/content", [
                'content' => "Updated notes\n",
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Notes');

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/documents/{$documentId}/content")
            ->assertOk()
            ->assertJsonPath('data.content', 'Updated notes');
    }
}
