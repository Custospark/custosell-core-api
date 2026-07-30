<?php

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Resources\PipelineSourceResource;
use App\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineSourceController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
    ) {}

    public function sources(Request $request): JsonResponse
    {
        $sources = $this->pipelineService->listSources((int) $request->user()->business_id);

        return response()->json([
            'data' => PipelineSourceResource::collection($sources),
        ]);
    }

    public function storeSource(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $source = $this->pipelineService->createSource(
            (int) $request->user()->business_id,
            $request->user(),
            $validated,
        );

        return (new PipelineSourceResource($source))
            ->response()
            ->setStatusCode(201);
    }

    public function updateSource(Request $request, int $id): PipelineSourceResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $source = $this->pipelineService->updateSource(
            (int) $request->user()->business_id,
            $id,
            $validated,
        );

        return new PipelineSourceResource($source);
    }

    public function destroySource(Request $request, int $id): JsonResponse
    {
        $this->pipelineService->deleteSource((int) $request->user()->business_id, $id);

        return response()->json(['message' => 'Source deleted']);
    }
}
