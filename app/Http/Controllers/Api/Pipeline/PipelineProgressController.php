<?php

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Services\Pipeline\PipelineBoardProgressService;
use App\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineProgressController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
        protected PipelineBoardProgressService $boardProgress,
    ) {}

    public function boardProgressSummary(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:day,week,month,quarter,year,custom'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'stage_ids' => ['nullable', 'array'],
            'stage_ids.*' => ['integer'],
        ]);

        $summary = $this->boardProgress->progressSummary(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['period'] ?? 'month',
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            $validated['stage_ids'] ?? null,
        );

        return response()->json(['data' => $summary]);
    }

    public function boardProgressQuery(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:day,week,month,quarter,year,custom'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'stage_ids' => ['nullable', 'array'],
            'stage_ids.*' => ['integer'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer'],
            'metrics' => ['nullable', 'array'],
            'metrics.*' => ['string'],
            'planning_level' => ['nullable', 'in:decade,five_year,year,quarter,month,week,day'],
        ]);

        $result = $this->boardProgress->progressQuery(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated,
        );

        return response()->json(['data' => $result]);
    }

    public function myBoardProgress(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:day,week,month,quarter,year,custom'],
        ]);

        $result = $this->boardProgress->myProgress(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['period'] ?? 'month',
        );

        return response()->json(['data' => $result]);
    }

    public function boardProgressConfig(Request $request, int $boardId): JsonResponse
    {
        $config = $this->boardProgress->getProgressConfig(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $config]);
    }

    public function updateBoardProgressConfig(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'charts' => ['nullable', 'array'],
            'funnel_mode' => ['nullable', 'in:count,value'],
        ]);

        $config = $this->boardProgress->saveProgressConfig(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated,
        );

        return response()->json(['data' => $config]);
    }

    public function decomposeBoardTargetPreview(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'planning_level' => ['required', 'in:decade,five_year,year,quarter,month,week,day'],
            'target_value' => ['required', 'numeric', 'min:0'],
            'anchor_start' => ['nullable', 'date'],
            'anchor_end' => ['nullable', 'date', 'after_or_equal:anchor_start'],
            'stage_ids' => ['required', 'array', 'min:1'],
            'stage_ids.*' => ['integer'],
            'member_user_ids' => ['nullable', 'array'],
            'member_user_ids.*' => ['integer'],
            'decomposition_mode' => ['nullable', 'in:equal,velocity,hybrid'],
        ]);

        $preview = $this->boardProgress->decomposePreview(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated,
        );

        return response()->json(['data' => $preview]);
    }

    public function boardTargets(Request $request, int $boardId): JsonResponse
    {
        $targets = $this->boardProgress->listTargets(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $targets]);
    }

    public function storeBoardTarget(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:kpi,goal,objective,key_result'],
            'goal_tag' => ['nullable', 'in:kpi,goal,objective,deliverable,decision'],
            'parent_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'metric_key' => ['required', 'string'],
            'target_value' => ['required', 'numeric', 'min:0'],
            'unit' => ['required', 'in:count,currency,percent,days'],
            'period_type' => ['required', 'in:day,week,month,quarter,year,custom'],
            'planning_level' => ['nullable', 'in:decade,five_year,year,quarter,month,week,day'],
            'anchor_start' => ['nullable', 'date'],
            'anchor_end' => ['nullable', 'date', 'after_or_equal:anchor_start'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'scope' => ['required', 'in:board,member'],
            'member_user_id' => ['nullable', 'integer'],
            'stage_id' => ['required', 'integer'],
            'stage_ids' => ['nullable', 'array'],
            'decomposition_mode' => ['nullable', 'in:equal,velocity,hybrid'],
            'weight' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'in:draft,active,paused,completed,archived'],
            'allocations' => ['nullable', 'array'],
            'key_results' => ['nullable', 'array'],
            'key_results.*.title' => ['required_with:key_results', 'string', 'max:255'],
            'key_results.*.metric_key' => ['required_with:key_results', 'string'],
            'key_results.*.target_value' => ['required_with:key_results', 'numeric', 'min:0'],
            'key_results.*.unit' => ['required_with:key_results', 'in:count,currency,percent,days'],
            'key_results.*.scope' => ['nullable', 'in:board,member'],
            'key_results.*.member_user_id' => ['nullable', 'integer'],
            'key_results.*.stage_id' => ['nullable', 'integer'],
        ]);

        $target = $this->boardProgress->storeTarget(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated,
        );

        return response()->json(['data' => $target], 201);
    }

    public function updateBoardTarget(Request $request, int $targetId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'metric_key' => ['sometimes', 'string'],
            'target_value' => ['sometimes', 'numeric', 'min:0'],
            'unit' => ['sometimes', 'in:count,currency,percent,days'],
            'period_type' => ['sometimes', 'in:day,week,month,quarter,year,custom'],
            'planning_level' => ['sometimes', 'in:decade,five_year,year,quarter,month,week,day'],
            'anchor_start' => ['nullable', 'date'],
            'anchor_end' => ['nullable', 'date', 'after_or_equal:anchor_start'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'scope' => ['sometimes', 'in:board,member'],
            'member_user_id' => ['nullable', 'integer'],
            'stage_id' => ['sometimes', 'integer'],
            'decomposition_mode' => ['nullable', 'in:equal,velocity,hybrid'],
            'weight' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'in:draft,active,paused,completed,archived'],
            'allocations' => ['nullable', 'array'],
        ]);

        $target = $this->boardProgress->updateTarget(
            (int) $request->user()->business_id,
            $request->user(),
            $targetId,
            $validated,
        );

        return response()->json(['data' => $target]);
    }

    public function destroyBoardTarget(Request $request, int $targetId): JsonResponse
    {
        $this->boardProgress->archiveTarget(
            (int) $request->user()->business_id,
            $request->user(),
            $targetId,
        );

        return response()->json(null, 204);
    }

    public function exportBoardProgress(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:day,week,month,quarter,year,custom'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $summary = $this->boardProgress->progressSummary(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['period'] ?? 'month',
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        );

        return response()->json([
            'data' => $summary,
            'exported_at' => now()->toIso8601String(),
        ]);
    }
}
