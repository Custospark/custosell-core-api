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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentCrossCabinetMoveTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected int $sourceCabinetId;

    protected int $targetCabinetId;

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

        $this->sourceCabinetId = (int) DocumentCabinet::query()
            ->where('business_id', $this->business->id)
            ->where('name', 'General')
            ->value('id');

        $target = DocumentCabinet::create([
            'business_id' => $this->business->id,
            'name' => 'Legal & Compliance',
            'created_by' => $this->owner->id,
            'visibility' => 'all_staff',
        ]);
        $this->targetCabinetId = (int) $target->id;
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->owner->createToken('test')->plainTextToken];
    }

    protected function makeFolder(int $cabinetId, ?int $parentId = null, string $name = 'Folder'): DocumentFolder
    {
        return DocumentFolder::create([
            'business_id' => $this->business->id,
            'cabinet_id' => $cabinetId,
            'parent_id' => $parentId,
            'name' => $name,
            'depth' => $parentId ? 2 : 1,
            'visibility' => 'all_staff',
            'created_by' => $this->owner->id,
        ]);
    }

    protected function makeDocument(int $cabinetId, ?int $folderId = null, string $title = 'Doc'): Document
    {
        $path = UploadedFile::fake()->create('doc.pdf', 5, 'application/pdf')->store('documents', 'public');

        return Document::create([
            'business_id' => $this->business->id,
            'cabinet_id' => $cabinetId,
            'folder_id' => $folderId,
            'title' => $title,
            'type' => 'file',
            'file_path' => $path,
            'file_name' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 5,
            'visibility' => 'all_staff',
            'uploaded_by' => $this->owner->id,
        ]);
    }

    public function test_folder_moves_to_another_cabinet_root(): void
    {
        $folder = $this->makeFolder($this->sourceCabinetId, null, 'Operations');

        $response = $this->withHeaders($this->headers())
            ->patchJson("/api/v1/documents/folders/{$folder->id}", [
                'name' => 'Operations',
                'visibility' => 'all_staff',
                'parent_id' => null,
                'cabinet_id' => $this->targetCabinetId,
            ]);

        $response->assertStatus(200);

        $fresh = $folder->fresh();
        $this->assertSame($this->targetCabinetId, (int) $fresh->cabinet_id);
        $this->assertNull($fresh->parent_id);
        $this->assertSame(1, (int) $fresh->depth);
    }

    public function test_folder_moves_into_another_cabinets_folder_and_reparentes_subtree(): void
    {
        $sourceParent = $this->makeFolder($this->sourceCabinetId, null, 'Parent');
        $child = $this->makeFolder($this->sourceCabinetId, $sourceParent->id, 'Child');
        $nestedDoc = $this->makeDocument($this->sourceCabinetId, $child->id, 'Nested file');

        $targetFolder = $this->makeFolder($this->targetCabinetId, null, 'Target Folder');

        $response = $this->withHeaders($this->headers())
            ->patchJson("/api/v1/documents/folders/{$sourceParent->id}", [
                'name' => 'Parent',
                'visibility' => 'all_staff',
                'parent_id' => $targetFolder->id,
                'cabinet_id' => $this->targetCabinetId,
            ]);

        $response->assertStatus(200);

        // The moved folder + its child + nested document all land in the target cabinet.
        $this->assertSame($this->targetCabinetId, (int) $sourceParent->fresh()->cabinet_id);
        $this->assertSame($this->targetCabinetId, (int) $child->fresh()->cabinet_id);
        $this->assertSame($this->targetCabinetId, (int) $nestedDoc->fresh()->cabinet_id);
        $this->assertSame($targetFolder->id, (int) $sourceParent->fresh()->parent_id);
        $this->assertSame(2, (int) $sourceParent->fresh()->depth);
        $this->assertSame(3, (int) $child->fresh()->depth);
    }

    public function test_document_moves_to_another_cabinet_root(): void
    {
        $doc = $this->makeDocument($this->sourceCabinetId, null, 'Policy file');

        $response = $this->withHeaders($this->headers())
            ->patchJson("/api/v1/documents/{$doc->id}", [
                'folder_id' => null,
                'cabinet_id' => $this->targetCabinetId,
            ]);

        $response->assertStatus(200);

        $fresh = $doc->fresh();
        $this->assertSame($this->targetCabinetId, (int) $fresh->cabinet_id);
        $this->assertNull($fresh->folder_id);
    }

    public function test_document_moves_into_another_cabinets_folder(): void
    {
        $doc = $this->makeDocument($this->sourceCabinetId, null, 'Doc');
        $targetFolder = $this->makeFolder($this->targetCabinetId, null, 'Target');

        $response = $this->withHeaders($this->headers())
            ->patchJson("/api/v1/documents/{$doc->id}", [
                'folder_id' => $targetFolder->id,
                'cabinet_id' => $this->targetCabinetId,
            ]);

        $response->assertStatus(200);

        $fresh = $doc->fresh();
        $this->assertSame($this->targetCabinetId, (int) $fresh->cabinet_id);
        $this->assertSame($targetFolder->id, (int) $fresh->folder_id);
    }

    public function test_cannot_move_folder_into_folder_of_different_cabinet_than_selected(): void
    {
        $folder = $this->makeFolder($this->sourceCabinetId, null, 'Ops');
        // A folder that still lives in the SOURCE cabinet.
        $otherSourceFolder = $this->makeFolder($this->sourceCabinetId, null, 'StillSource');

        $response = $this->withHeaders($this->headers())
            ->patchJson("/api/v1/documents/folders/{$folder->id}", [
                'name' => 'Ops',
                'visibility' => 'all_staff',
                'parent_id' => $otherSourceFolder->id,
                'cabinet_id' => $this->targetCabinetId,
            ]);

        $response->assertStatus(422);
        $this->assertSame($this->sourceCabinetId, (int) $folder->fresh()->cabinet_id);
    }
}