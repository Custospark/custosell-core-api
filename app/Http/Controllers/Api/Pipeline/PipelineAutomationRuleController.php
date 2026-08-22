<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Services\Contracts\PipelineAutomationRuleServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineAutomationRuleController extends Controller
{
    public function __construct(
        protected PipelineAutomationRuleServiceInterface $rules,
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
}