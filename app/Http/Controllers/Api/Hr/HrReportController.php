<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\HrAuditService;
use App\Services\Hr\HrReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrReportController extends Controller
{
    public function __construct(
        protected HrReportService $reports,
        protected HrAuditService $audit,
    ) {}

    public function payeSchedule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pay_run_id' => ['nullable', 'integer'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
        ]);

        $businessId = (int) $request->user()->business_id;

        if (! empty($validated['pay_run_id'])) {
            return response()->json([
                'data' => $this->reports->payeSchedule($businessId, (int) $validated['pay_run_id']),
            ]);
        }

        if (empty($validated['period_start']) || empty($validated['period_end'])) {
            return response()->json([
                'message' => 'Provide pay_run_id or period_start and period_end.',
                'errors' => ['pay_run_id' => ['Required when period is not provided.']],
            ], 422);
        }

        return response()->json([
            'data' => $this->reports->payeScheduleForPeriod(
                $businessId,
                $validated['period_start'],
                $validated['period_end'],
            ),
        ]);
    }

    public function nssfSchedule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pay_run_id' => ['nullable', 'integer'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
        ]);

        $businessId = (int) $request->user()->business_id;

        if (! empty($validated['pay_run_id'])) {
            return response()->json([
                'data' => $this->reports->nssfSchedule($businessId, (int) $validated['pay_run_id']),
            ]);
        }

        if (empty($validated['period_start']) || empty($validated['period_end'])) {
            return response()->json([
                'message' => 'Provide pay_run_id or period_start and period_end.',
                'errors' => ['pay_run_id' => ['Required when period is not provided.']],
            ], 422);
        }

        return response()->json([
            'data' => $this->reports->nssfScheduleForPeriod(
                $businessId,
                $validated['period_start'],
                $validated['period_end'],
            ),
        ]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string'],
            'subject_type' => ['nullable', 'string'],
            'subject_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $paginator = $this->audit->list(
            (int) $request->user()->business_id,
            $validated,
            (int) ($validated['per_page'] ?? 50),
        );

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
