<?php

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Resources\PipelineLabelResource;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineLabelController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
    ) {}

    public function labels(Request $request): JsonResponse
    {
        $validated = $request->validate(['board_id' => ['nullable', 'integer']]);

        $labels = $this->pipelineService->listLabels(
            (int) $request->user()->business_id,
            $request->user(),
            $validated['board_id'] ?? null,
        );

        return response()->json(['data' => PipelineLabelResource::collection($labels)]);
    }

    public function storeLabel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'board_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:32'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $label = $this->pipelineService->createLabel(
            (int) $request->user()->business_id,
            $request->user(),
            $validated,
        );

        return (new PipelineLabelResource($label))->response()->setStatusCode(201);
    }

    public function updateLabel(Request $request, int $id): PipelineLabelResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:32'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $label = $this->pipelineService->updateLabel(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated,
        );

        return new PipelineLabelResource($label);
    }

    public function destroyLabel(Request $request, int $id): JsonResponse
    {
        $this->pipelineService->deleteLabel((int) $request->user()->business_id, $request->user(), $id);

        return response()->json(['message' => 'Label deleted']);
    }
}
