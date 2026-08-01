<?php

namespace Tests\Feature;

use App\Models\Business;
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

class DocumentsContentTest extends TestCase
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

        $this->ensureSubscription($this->business->id, \App\Models\Plan::where('slug', 'enterprise')->first()?->id);

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

    public function test_cabinet_member_roles_viewer_contributor_manager(): void
    {
        $viewer = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['documents'],
        ]);
        $contributor = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['documents'],
        ]);
        $manager = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'modules' => ['documents'],
        ]);

        $cabinetResponse = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/documents/cabinets', [
                'name' => 'Role Matrix Cabinet',
                'visibility' => 'selected_staff',
                'member_user_ids' => [$viewer->id, $contributor->id, $manager->id],
                'member_roles' => [
                    (string) $viewer->id => 'viewer',
                    (string) $contributor->id => 'contributor',
                    (string) $manager->id => 'manager',
                ],
            ])
            ->assertCreated();

        $cabinetId = (int) $cabinetResponse->json('data.id');

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/documents/cabinets/{$cabinetId}")
            ->assertOk()
            ->assertJsonPath('data.can_view', true)
            ->assertJsonPath('data.can_contribute', false)
            ->assertJsonPath('data.can_manage', false)
            ->assertJsonPath('data.current_member_role', 'viewer');

        $this->actingAs($contributor, 'sanctum')
            ->getJson("/api/v1/documents/cabinets/{$cabinetId}")
            ->assertOk()
            ->assertJsonPath('data.can_view', true)
            ->assertJsonPath('data.can_contribute', true)
            ->assertJsonPath('data.can_manage', false)
            ->assertJsonPath('data.current_member_role', 'contributor');

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/documents/cabinets/{$cabinetId}")
            ->assertOk()
            ->assertJsonPath('data.can_view', true)
            ->assertJsonPath('data.can_contribute', true)
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.current_member_role', 'manager');

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/documents/folders', [
                'name' => 'Viewer folder',
                'visibility' => 'inherit',
                'cabinet_id' => $cabinetId,
            ])
            ->assertStatus(403);

        $folderId = (int) $this->actingAs($contributor, 'sanctum')
            ->postJson('/api/v1/documents/folders', [
                'name' => 'Contributor folder',
                'visibility' => 'inherit',
                'cabinet_id' => $cabinetId,
            ])
            ->assertCreated()
            ->json('data.id');

        $ownerDocId = (int) $this->actingAs($this->owner, 'sanctum')
            ->post('/api/v1/documents/upload', [
                'file' => UploadedFile::fake()->create('owner.pdf', 50, 'application/pdf'),
                'folder_id' => $folderId,
                'title' => 'Owner file',
                'visibility' => 'inherit',
            ])
            ->assertCreated()
            ->json('data.id');

        $contributorDocId = (int) $this->actingAs($contributor, 'sanctum')
            ->post('/api/v1/documents/upload', [
                'file' => UploadedFile::fake()->create('mine.pdf', 50, 'application/pdf'),
                'folder_id' => $folderId,
                'title' => 'Contributor file',
                'visibility' => 'inherit',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($contributor, 'sanctum')
            ->patchJson("/api/v1/documents/{$contributorDocId}", ['title' => 'Renamed mine'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed mine');

        $this->actingAs($contributor, 'sanctum')
            ->patchJson("/api/v1/documents/{$ownerDocId}", ['title' => 'Hijack'])
            ->assertStatus(403);

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/documents/{$ownerDocId}", ['title' => 'Manager rename'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Manager rename');

        $this->actingAs($contributor, 'sanctum')
            ->patchJson("/api/v1/documents/folders/{$folderId}", ['name' => 'Nope'])
            ->assertStatus(403);

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/documents/folders/{$folderId}", ['name' => 'Managed folder'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Managed folder');

        $this->actingAs($contributor, 'sanctum')
            ->patchJson("/api/v1/documents/cabinets/{$cabinetId}", ['name' => 'Nope cabinet'])
            ->assertStatus(403);

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/documents/cabinets/{$cabinetId}", ['description' => 'Managed by role'])
            ->assertOk()
            ->assertJsonPath('data.description', 'Managed by role');
    }
}
