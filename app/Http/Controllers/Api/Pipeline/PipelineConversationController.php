<?php

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Services\PipelineService;
use App\Services\Pipeline\PipelineBoardConversationService;
use App\Services\Pipeline\PipelineBoardActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineConversationController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
        protected PipelineBoardConversationService $boardConversation,
        protected PipelineBoardActivityService $boardActivity,
    ) {}

    public function boardConversationSummary(Request $request, int $boardId): JsonResponse
    {
        $summary = $this->boardConversation->conversationSummary(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $summary]);
    }

    public function boardConversationMessages(Request $request, int $boardId): JsonResponse
    {
        $messages = $this->boardConversation->listMessages(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $messages]);
    }

    public function storeBoardConversationMessage(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $message = $this->boardConversation->storeMessage(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['body'],
            $validated['parent_id'] ?? null,
        );

        return response()->json(['data' => $message], 201);
    }

    public function updateBoardConversationMessage(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $message = $this->boardConversation->updateMessage(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated['body'],
        );

        return response()->json(['data' => $message]);
    }

    public function destroyBoardConversationMessage(Request $request, int $id): JsonResponse
    {
        $this->boardConversation->deleteMessage(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['message' => 'Message deleted']);
    }

    public function toggleBoardConversationReaction(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reaction' => ['nullable', 'string', 'max:32'],
        ]);

        $summary = $this->boardConversation->toggleReaction(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated['reaction'] ?? null,
        );

        return response()->json(['data' => $summary]);
    }

    public function markBoardConversationRead(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'last_read_message_id' => ['nullable', 'integer'],
        ]);

        $state = $this->boardConversation->markConversationRead(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['last_read_message_id'] ?? null,
        );

        return response()->json(['data' => $state]);
    }

    public function boardConversationActivity(Request $request, int $boardId): JsonResponse
    {
        $events = $this->boardActivity->listActivity(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $events]);
    }

    public function toggleBoardConversationPin(Request $request, int $id): JsonResponse
    {
        $message = $this->boardConversation->togglePin(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['data' => $message]);
    }

    public function uploadBoardConversationAttachment(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $attachment = $this->boardConversation->uploadAttachment(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated['file'],
        );

        return response()->json(['data' => $attachment], 201);
    }

    public function destroyBoardConversationAttachment(Request $request, int $id): JsonResponse
    {
        $this->boardConversation->deleteAttachment(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['message' => 'Attachment deleted']);
    }
}
