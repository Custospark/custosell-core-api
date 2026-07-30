<?php

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Resources\PipelineChecklistItemResource;
use App\Http\Resources\PipelineChecklistResource;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineChecklistController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
    ) {}

    public function storeChecklist(Request $request, int $leadId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $checklist = $this->pipelineService->createChecklist(
            (int) $request->user()->business_id,
            $request->user(),
            $leadId,
            $validated,
        );

        return (new PipelineChecklistResource($checklist))->response()->setStatusCode(201);
    }

    public function updateChecklist(Request $request, int $id): PipelineChecklistResource
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $checklist = $this->pipelineService->updateChecklist(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated,
        );

        return new PipelineChecklistResource($checklist);
    }

    public function destroyChecklist(Request $request, int $id): JsonResponse
    {
        $this->pipelineService->deleteChecklist((int) $request->user()->business_id, $request->user(), $id);

        return response()->json(['message' => 'Checklist deleted']);
    }

    public function storeChecklistItem(Request $request, int $checklistId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_done' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $item = $this->pipelineService->createChecklistItem(
            (int) $request->user()->business_id,
            $request->user(),
            $checklistId,
            $validated,
        );

        return (new PipelineChecklistItemResource($item))->response()->setStatusCode(201);
    }

    public function updateChecklistItem(Request $request, int $id): PipelineChecklistItemResource
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_done' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $item = $this->pipelineService->updateChecklistItem(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated,
        );

        return new PipelineChecklistItemResource($item);
    }

    public function destroyChecklistItem(Request $request, int $id): JsonResponse
    {
        $this->pipelineService->deleteChecklistItem((int) $request->user()->business_id, $request->user(), $id);

        return response()->json(['message' => 'Checklist item deleted']);
    }
}
