<?php

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Pipeline\PipelineCollaborationService;
use App\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PipelineCollaborationController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
        protected PipelineCollaborationService $collaboration,
    ) {}

    public function boardCollaborationSummary(Request $request, int $boardId): JsonResponse
    {
        $summary = $this->collaboration->boardCollaborationSummary(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $summary]);
    }

    public function boardAnnouncements(Request $request, int $boardId): JsonResponse
    {
        $items = $this->collaboration->listAnnouncements(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $items]);
    }

    public function storeBoardAnnouncement(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        $item = $this->collaboration->createAnnouncement(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['title'],
            $validated['body'],
            (bool) ($validated['is_pinned'] ?? false),
        );

        return response()->json(['data' => $item], 201);
    }

    public function destroyBoardAnnouncement(Request $request, int $id): JsonResponse
    {
        $this->collaboration->deleteAnnouncement(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['message' => 'Announcement removed']);
    }

    public function setAnnouncementRead(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'is_read' => ['required', 'boolean'],
        ]);

        $item = $this->collaboration->setAnnouncementReadState(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            (bool) $validated['is_read'],
        );

        return response()->json(['data' => $item]);
    }

    public function boardPolls(Request $request, int $boardId): JsonResponse
    {
        $leadId = $request->query('lead_id') ? (int) $request->query('lead_id') : null;
        $polls = $this->collaboration->listPolls(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $leadId,
        );

        return response()->json(['data' => $polls]);
    }

    public function storeBoardPoll(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'options' => ['required', 'array', 'min:2', 'max:12'],
            'options.*' => ['required', 'string', 'max:255'],
            'lead_id' => ['nullable', 'integer'],
            'closes_at' => ['nullable', 'date'],
            'results_visibility' => ['sometimes', 'in:team,creator_only'],
        ]);

        $poll = $this->collaboration->createPoll(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['question'],
            $validated['options'],
            $validated['lead_id'] ?? null,
            $validated['closes_at'] ?? null,
            $validated['results_visibility'] ?? 'team',
        );

        return response()->json(['data' => $poll], 201);
    }

    public function updatePoll(Request $request, int $pollId): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['sometimes', 'string', 'max:500'],
            'options' => ['sometimes', 'array', 'min:2', 'max:12'],
            'options.*.id' => ['sometimes', 'integer'],
            'options.*.label' => ['required_with:options', 'string', 'max:255'],
            'closes_at' => ['nullable', 'date'],
            'results_visibility' => ['sometimes', 'in:team,creator_only'],
        ]);

        $poll = $this->collaboration->updatePoll(
            (int) $request->user()->business_id,
            $request->user(),
            $pollId,
            $validated,
        );

        return response()->json(['data' => $poll]);
    }

    public function votePoll(Request $request, int $pollId): JsonResponse
    {
        $validated = $request->validate([
            'option_id' => ['required', 'integer'],
        ]);

        $poll = $this->collaboration->votePoll(
            (int) $request->user()->business_id,
            $request->user(),
            $pollId,
            (int) $validated['option_id'],
        );

        return response()->json(['data' => $poll]);
    }

    public function removePollVote(Request $request, int $pollId): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
        ]);

        $poll = $this->collaboration->removePollVote(
            (int) $request->user()->business_id,
            $request->user(),
            $pollId,
            isset($validated['user_id']) ? (int) $validated['user_id'] : null,
        );

        return response()->json(['data' => $poll]);
    }

    public function destroyPoll(Request $request, int $pollId): JsonResponse
    {
        $this->collaboration->deletePoll(
            (int) $request->user()->business_id,
            $request->user(),
            $pollId,
        );

        return response()->json(['message' => 'Poll removed']);
    }

    public function leadReminders(Request $request, int $leadId): JsonResponse
    {
        $items = $this->collaboration->listReminders(
            (int) $request->user()->business_id,
            $request->user(),
            $leadId,
        );

        return response()->json(['data' => $items]);
    }

    public function storeLeadReminder(Request $request, int $leadId): JsonResponse
    {
        $validated = $request->validate([
            'remind_at' => ['required', 'date'],
            'message' => ['nullable', 'string', 'max:500'],
            'channel' => ['nullable', 'in:in_app,email,both'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $reminder = $this->collaboration->createReminder(
            (int) $request->user()->business_id,
            $request->user(),
            $leadId,
            $validated['remind_at'],
            $validated['message'] ?? null,
            $validated['channel'] ?? 'both',
            $validated['user_id'] ?? null,
        );

        return response()->json(['data' => $reminder], 201);
    }

    public function destroyReminder(Request $request, int $id): JsonResponse
    {
        $this->collaboration->cancelReminder(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['message' => 'Reminder cancelled']);
    }
}
