<?php

namespace App\Services\Pipeline;

use App\Models\PipelineAttachment;
use App\Models\PipelineLead;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PipelineAttachmentService
{
    public function __construct(
        protected PipelineService $pipelineService,
    ) {}

    public function addAttachment(int $businessId, User $user, int $leadId, UploadedFile $file): PipelineAttachment
    {
        $lead = $this->pipelineService->findLeadForBusiness($businessId, $leadId);
        $this->pipelineService->assertCanEditBoard($user, $lead->board);

        $path = $file->store('pipeline-attachments', 'public');
        $fileName = $file->getClientOriginalName();

        $attachment = PipelineAttachment::create([
            'lead_id' => $leadId,
            'user_id' => $user->id,
            'type' => 'file',
            'file_name' => $fileName,
            'file_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
        ]);

        $this->pipelineService->recordActivity($lead, $user->id, 'system', "Attachment added: {$fileName}", [
            'action' => 'attachment_added',
            'file_name' => $fileName,
        ]);

        return $attachment;
    }

    public function addAttachmentLink(int $businessId, User $user, int $leadId, string $url, ?string $title): PipelineAttachment
    {
        $lead = $this->pipelineService->findLeadForBusiness($businessId, $leadId);
        $this->pipelineService->assertCanEditBoard($user, $lead->board);

        $label = $title ?: $url;

        $attachment = PipelineAttachment::create([
            'lead_id' => $leadId,
            'user_id' => $user->id,
            'type' => 'link',
            'file_name' => $label,
            'file_path' => null,
            'link_url' => $url,
            'mime_type' => null,
            'file_size' => null,
        ]);

        $this->pipelineService->recordActivity($lead, $user->id, 'system', "Link added: {$label}", [
            'action' => 'link_added',
            'url' => $url,
            'title' => $title,
        ]);

        return $attachment;
    }

    public function deleteAttachment(int $businessId, User $user, int $attachmentId): void
    {
        $attachment = PipelineAttachment::query()
            ->with('lead.board')
            ->findOrFail($attachmentId);

        if ((int) $attachment->lead->business_id !== $businessId) {
            abort(404);
        }

        $this->pipelineService->assertCanEditBoard($user, $attachment->lead->board);

        if ($attachment->file_path && $attachment->type !== 'link') {
            Storage::disk('public')->delete($attachment->file_path);
        }

        $fileName = $attachment->file_name;
        $lead = $attachment->lead;

        $attachment->delete();

        $this->pipelineService->recordActivity($lead, $user->id, 'system', "Attachment removed: {$fileName}", [
            'action' => 'attachment_removed',
            'file_name' => $fileName,
        ]);
    }
}
