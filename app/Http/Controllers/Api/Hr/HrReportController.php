<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\HrAuditService;
use App\Services\Hr\HrPayrollAffordabilityService;
use App\Services\Hr\HrReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrReportController extends Controller
{
    public function __construct(
        protected HrReportService $reports,
        protected HrAuditService $audit,
        protected HrPayrollAffordabilityService $affordability,
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

    public function payrollAffordability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of_date' => ['nullable', 'date'],
            'period_id' => ['nullable', 'integer'],
            'horizon_months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'hire' => ['nullable', 'array'],
            'hire.basic_salary' => ['required_with:hire', 'numeric', 'gt:0'],
            'hire.allowances' => ['nullable', 'array'],
            'hire.deductions' => ['nullable', 'array'],
            'hire.start_month_offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $data = $this->affordability->analyze(
            (int) $request->user()->business_id,
            $validated['as_of_date'] ?? null,
            isset($validated['period_id']) ? (int) $validated['period_id'] : null,
            (int) ($validated['horizon_months'] ?? 3),
            $validated['hire'] ?? null,
        );

        return response()->json(['data' => $data]);
    }
}
