<?php

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Pipeline\PipelineBoardTemplateService;
use App\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineTemplateController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
        protected PipelineBoardTemplateService $boardTemplates,
    ) {}

    public function boardTemplates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workspace' => ['nullable', 'in:pipeline,estimates'],
        ]);

        $templates = $this->boardTemplates->listTemplates(
            (int) $request->user()->business_id,
            $request->user(),
            $validated['workspace'] ?? 'pipeline',
        );

        return response()->json(['data' => $templates]);
    }

    public function storeBoardTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'workspace' => ['nullable', 'in:pipeline,estimates'],
            'stages' => ['nullable', 'array'],
            'labels' => ['nullable', 'array'],
            'resources' => ['nullable', 'array'],
            'automations' => ['nullable', 'array'],
        ]);

        $template = $this->boardTemplates->createTemplate(
            (int) $request->user()->business_id,
            $request->user(),
            $validated['name'],
            $validated['workspace'] ?? 'pipeline',
            $validated['description'] ?? null,
            $validated['stages'] ?? null,
            $validated['labels'] ?? null,
            $validated['resources'] ?? null,
            $validated['automations'] ?? null,
        );

        return response()->json(['data' => $template], 201);
    }

    public function applyBoardTemplate(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'template_id' => ['required', 'integer'],
        ]);

        $this->boardTemplates->applyTemplate(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            (int) $validated['template_id'],
        );

        return response()->json(['message' => 'Template applied']);
    }
}
