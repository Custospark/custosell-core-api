<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Models\PipelineAutomationRun;
use App\Repositories\Contracts\PipelineAutomationRuleRepositoryInterface;
use App\Services\Contracts\PipelineAutomationRuleServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineAutomationRuleController extends Controller
{
    public function __construct(
        protected PipelineAutomationRuleServiceInterface $rules,
        protected PipelineAutomationRuleRepositoryInterface $repository,
    ) {}

    public function index(Request $request, int $boardId): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->rules->listForBoard($businessId, $request->user(), $boardId),
        ]);
    }

    public function store(Request $request, int $boardId): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->rules->createRule($businessId, $request->user(), $boardId, $request->all()),
        ], 201);
    }

    public function update(Request $request, int $ruleId): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->rules->updateRule($businessId, $request->user(), $ruleId, $request->all()),
        ]);
    }

    public function destroy(Request $request, int $ruleId): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $this->rules->deleteRule($businessId, $request->user(), $ruleId);

        return response()->json(['data' => null, 'message' => 'Automation deleted.']);
    }

    public function toggle(Request $request, int $ruleId): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $active = (bool) $request->boolean('is_active');

        return response()->json([
            'data' => $this->rules->toggleRule($businessId, $request->user(), $ruleId, $active),
        ]);
    }

    public function runs(Request $request, int $ruleId): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $rule = $this->repository->find($ruleId);
        if (! $rule || (int) $rule->business_id !== $businessId) {
            abort(404, 'Automation rule not found.');
        }

        $runs = $this->repository->recentRuns($ruleId, (int) $request->query('limit', 20));

        return response()->json([
            'data' => $runs->map(fn (PipelineAutomationRun $run) => [
                'id' => $run->id,
                'rule_id' => $run->rule_id,
                'lead_id' => $run->lead_id,
                'trigger' => $run->trigger,
                'status' => $run->status,
                'actions_executed' => $run->actions_executed,
                'message' => $run->message,
                'detail' => $run->detail,
                'lead_title' => $run->lead?->title,
                'created_at' => $run->created_at?->toISOString(),
            ])->values()->all(),
        ]);
    }
}