<?php

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Resources\PipelineStageResource;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineStageController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
    ) {}

    public function storeStage(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:32'],
            'is_won' => ['sometimes', 'boolean'],
            'is_lost' => ['sometimes', 'boolean'],
            'rotting_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $stage = $this->pipelineService->createStage(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated,
        );

        return (new PipelineStageResource($stage))
            ->response()
            ->setStatusCode(201);
    }

    public function updateStage(Request $request, int $stageId): PipelineStageResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:32'],
            'is_won' => ['sometimes', 'boolean'],
            'is_lost' => ['sometimes', 'boolean'],
            'rotting_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $stage = $this->pipelineService->updateStage(
            (int) $request->user()->business_id,
            $request->user(),
            $stageId,
            $validated,
        );

        return new PipelineStageResource($stage);
    }

    public function reorderStages(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'stage_ids' => ['required', 'array', 'min:1'],
            'stage_ids.*' => ['integer'],
        ]);

        $stages = $this->pipelineService->reorderStages(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['stage_ids'],
        );

        return response()->json([
            'data' => PipelineStageResource::collection($stages),
        ]);
    }

    public function destroyStage(Request $request, int $stageId): JsonResponse
    {
        $validated = $request->validate([
            'migrate_to_stage_id' => ['nullable', 'integer'],
        ]);

        $this->pipelineService->deleteStage(
            (int) $request->user()->business_id,
            $request->user(),
            $stageId,
            $validated['migrate_to_stage_id'] ?? null,
        );

        return response()->json(['message' => 'Stage deleted']);
    }
}
