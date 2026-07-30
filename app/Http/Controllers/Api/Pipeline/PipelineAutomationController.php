<?php

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Services\PipelineService;
use App\Services\Pipeline\PipelineBoardAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineAutomationController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
        protected PipelineBoardAutomationService $boardAutomations,
    ) {}

    public function boardAutomations(Request $request, int $boardId): JsonResponse
    {
        $items = $this->boardAutomations->listAutomations(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $items]);
    }

    public function storeBoardAutomation(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger_type' => ['required', 'in:stage_entered,status_won,status_lost'],
            'trigger_stage_id' => ['nullable', 'integer'],
            'action_type' => ['required', 'in:conversation_post,conversation_notify'],
            'action_body' => ['required', 'string', 'max:2000'],
        ]);

        $automation = $this->boardAutomations->createAutomation(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['name'],
            $validated['trigger_type'],
            $validated['action_type'],
            $validated['action_body'],
            $validated['trigger_stage_id'] ?? null,
        );

        return response()->json(['data' => $automation], 201);
    }

    public function syncBoardAutomations(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'rules' => ['required', 'array'],
            'rules.*.trigger_type' => ['required', 'in:stage_entered,status_won,status_lost'],
            'rules.*.trigger_stage_id' => ['nullable', 'integer'],
            'rules.*.action_body' => ['required', 'string', 'max:2000'],
            'rules.*.is_active' => ['nullable', 'boolean'],
        ]);

        $automations = $this->boardAutomations->syncBoardAutomations(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['rules'],
        );

        return response()->json(['data' => $automations]);
    }

    public function destroyBoardAutomation(Request $request, int $id): JsonResponse
    {
        $this->boardAutomations->deleteAutomation(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['message' => 'Automation deleted']);
    }
}
