<?php

namespace App\Services;

use App\Models\IncomeSource;
use App\Models\IncomeSourceAttachment;
use App\Models\User;
use App\Services\Contracts\IncomeSourceServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class IncomeSourceAttachmentService
{
    public function __construct(
        protected IncomeSourceServiceInterface $incomeSourceService,
    ) {}

    public function addAttachment(int $businessId, User $user, int $incomeSourceId, UploadedFile $file): IncomeSourceAttachment
    {
        $incomeSource = $this->incomeSourceService->getById($incomeSourceId);
        if (!$incomeSource || (int) $incomeSource->business_id !== $businessId) {
            abort(404, 'Income source not found');
        }

        $path = $file->store('income-source-attachments', 'public');
        $fileName = $file->getClientOriginalName();

        return IncomeSourceAttachment::create([
            'income_source_id' => $incomeSourceId,
            'user_id' => $user->id,
            'type' => 'file',
            'file_name' => $fileName,
            'file_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
        ]);
    }

    public function addAttachmentLink(int $businessId, User $user, int $incomeSourceId, string $url, ?string $title): IncomeSourceAttachment
    {
        $incomeSource = $this->incomeSourceService->getById($incomeSourceId);
        if (!$incomeSource || (int) $incomeSource->business_id !== $businessId) {
            abort(404, 'Income source not found');
        }

        $label = $title ?: $url;

        return IncomeSourceAttachment::create([
            'income_source_id' => $incomeSourceId,
            'user_id' => $user->id,
            'type' => 'link',
            'file_name' => $label,
            'file_path' => null,
            'link_url' => $url,
            'mime_type' => null,
            'file_size' => null,
        ]);
    }

    public function deleteAttachment(int $businessId, User $user, int $attachmentId): void
    {
        $attachment = IncomeSourceAttachment::with('incomeSource')->findOrFail($attachmentId);

        if ((int) $attachment->incomeSource->business_id !== $businessId) {
            abort(404);
        }

        if ($attachment->file_path && $attachment->type !== 'link') {
            Storage::disk('public')->delete($attachment->file_path);
        }

        $attachment->delete();
    }
}
