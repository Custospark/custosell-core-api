<?php

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineCalendarController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
    ) {}

    public function calendar(Request $request, int|string $boardRef): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'date_field' => ['nullable', 'in:due,start,close,all'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        $days = $this->pipelineService->boardCalendar(
            (int) $request->user()->business_id,
            $request->user(),
            $boardRef,
            (int) $validated['year'],
            (int) $validated['month'],
            $validated['date_field'] ?? 'due',
            $validated['timezone'] ?? 'UTC',
        );

        return response()->json(['data' => $days]);
    }

    public function allBoardsCalendar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'date_field' => ['nullable', 'in:due,start,close,all'],
            'workspace' => ['nullable', 'in:pipeline,estimates'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        $days = $this->pipelineService->allBoardsCalendar(
            (int) $request->user()->business_id,
            $request->user(),
            (int) $validated['year'],
            (int) $validated['month'],
            $validated['date_field'] ?? 'due',
            $validated['workspace'] ?? 'pipeline',
            $validated['timezone'] ?? 'UTC',
        );

        return response()->json(['data' => $days]);
    }

    public function insights(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'board_id' => ['nullable', 'integer'],
        ]);

        $summary = $this->pipelineService->insightsSummary(
            (int) $request->user()->business_id,
            $request->user(),
            $validated['board_id'] ?? null,
        );

        return response()->json(['data' => $summary]);
    }
}
