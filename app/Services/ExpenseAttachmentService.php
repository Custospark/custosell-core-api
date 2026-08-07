<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\User;
use App\Services\Contracts\ExpenseServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ExpenseAttachmentService
{
    public function __construct(
        protected ExpenseServiceInterface $expenseService,
    ) {}

    public function addAttachment(int $businessId, User $user, int $expenseId, UploadedFile $file): ExpenseAttachment
    {
        $expense = $this->assertExpenseBelongsToBusiness($businessId, $expenseId);

        $path = $file->store('expense-attachments', 'public');
        $fileName = $file->getClientOriginalName();

        return ExpenseAttachment::create([
            'expense_id' => $expense->id,
            'user_id' => $user->id,
            'type' => 'file',
            'file_name' => $fileName,
            'file_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
        ]);
    }

    public function addAttachmentLink(int $businessId, User $user, int $expenseId, string $url, ?string $title): ExpenseAttachment
    {
        $expense = $this->assertExpenseBelongsToBusiness($businessId, $expenseId);

        $label = $title ?: $url;

        return ExpenseAttachment::create([
            'expense_id' => $expense->id,
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
        $attachment = ExpenseAttachment::with('expense')->findOrFail($attachmentId);

        if ((int) $attachment->expense->business_id !== $businessId) {
            abort(404);
        }

        if ($attachment->file_path && $attachment->type !== 'link') {
            Storage::disk('public')->delete($attachment->file_path);
        }

        $attachment->delete();
    }

    protected function assertExpenseBelongsToBusiness(int $businessId, int $expenseId): Expense
    {
        $expense = $this->expenseService->getById($expenseId);
        if (!$expense || (int) $expense->business_id !== $businessId) {
            abort(404, 'Expense not found');
        }
        return $expense;
    }
}