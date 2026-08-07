<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseAttachmentResource;
use App\Services\ExpenseAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseAttachmentController extends Controller
{
    public function __construct(
        protected ExpenseAttachmentService $attachmentService,
    ) {}

    public function store(Request $request, int $expenseId): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,pdf,doc,docx,xlsx,txt,csv'],
        ]);

        $attachment = $this->attachmentService->addAttachment(
            (int) $request->user()->business_id,
            $request->user(),
            $expenseId,
            $request->file('file'),
        );

        return (new ExpenseAttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function storeLink(Request $request, int $expenseId): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $attachment = $this->attachmentService->addAttachmentLink(
            (int) $request->user()->business_id,
            $request->user(),
            $expenseId,
            $validated['url'],
            $validated['title'] ?? null,
        );

        return (new ExpenseAttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->attachmentService->deleteAttachment(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['message' => 'Attachment deleted']);
    }
}