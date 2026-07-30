<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\IncomeSourceAttachmentResource;
use App\Services\IncomeSourceAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncomeSourceAttachmentController extends Controller
{
    public function __construct(
        protected IncomeSourceAttachmentService $attachmentService,
    ) {}

    public function store(Request $request, int $incomeSourceId): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,pdf,doc,docx,xlsx,txt,csv'],
        ]);

        $attachment = $this->attachmentService->addAttachment(
            (int) $request->user()->business_id,
            $request->user(),
            $incomeSourceId,
            $request->file('file'),
        );

        return (new IncomeSourceAttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function storeLink(Request $request, int $incomeSourceId): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $attachment = $this->attachmentService->addAttachmentLink(
            (int) $request->user()->business_id,
            $request->user(),
            $incomeSourceId,
            $validated['url'],
            $validated['title'] ?? null,
        );

        return (new IncomeSourceAttachmentResource($attachment))->response()->setStatusCode(201);
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
