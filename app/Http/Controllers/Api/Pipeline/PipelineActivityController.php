<?php

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Resources\PipelineLeadActivityResource;
use App\Services\PipelineService;
use App\Services\Pipeline\PipelineCollaborationService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineActivityController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
        protected PipelineCollaborationService $collaboration,
    ) {}

    public function storeActivity(Request $request, int $leadId): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:note,comment,call,email,meeting'],
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $activity = $this->pipelineService->addActivityAndNotify(
            (int) $request->user()->business_id,
            $request->user(),
            $leadId,
            $validated['type'],
            $validated['body'],
            null,
            $validated['parent_id'] ?? null,
        );

        $activity->load(['user:id,name,avatar', 'lead.board']);
        $canModerate = $activity->lead?->board
            ? $this->pipelineService->userCanManageBoard($request->user(), $activity->lead->board)
            : false;
        $request->attributes->set('pipeline_can_moderate_board', $canModerate);

        return (new PipelineLeadActivityResource($activity))
            ->response()
            ->setStatusCode(201);
    }

    public function destroyActivity(Request $request, int $id): JsonResponse
    {
        $this->pipelineService->deleteActivity(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(null, 204);
    }

    public function updateActivity(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $activity = $this->pipelineService->updateActivity(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated['body'],
        );

        $activity->loadMissing('lead.board');
        $canModerate = $activity->lead?->board
            ? $this->pipelineService->userCanManageBoard($request->user(), $activity->lead->board)
            : false;
        $request->attributes->set('pipeline_can_moderate_board', $canModerate);

        return (new PipelineLeadActivityResource($activity))
            ->response();
    }

    public function toggleActivityReaction(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reaction' => ['nullable', 'in:like,dislike'],
        ]);

        $summary = $this->collaboration->toggleReaction(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated['reaction'] ?? null,
        );

        return response()->json(['data' => $summary]);
    }
}
