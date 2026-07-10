<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\HrPayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrPayrollController extends Controller
{
    public function __construct(
        protected HrPayrollService $payroll,
    ) {}

    public function indexStructures(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->payroll->listStructures((int) $request->user()->business_id),
        ]);
    }

    public function storeStructure(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'max:8'],
        ]);

        $structure = $this->payroll->createStructure(
            (int) $request->user()->business_id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $structure], 201);
    }

    public function updateStructure(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'max:8'],
        ]);

        $structure = $this->payroll->updateStructure(
            (int) $request->user()->business_id,
            $id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $structure]);
    }

    public function indexCompensations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $this->payroll->listCompensations(
                (int) $request->user()->business_id,
                isset($validated['employee_id']) ? (int) $validated['employee_id'] : null,
            ),
        ]);
    }

    public function storeCompensation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'structure_id' => ['nullable', 'integer'],
            'basic_salary' => ['required', 'numeric', 'min:0'],
            'allowances_json' => ['nullable', 'array'],
            'allowances' => ['nullable', 'array'],
            'deductions_json' => ['nullable', 'array'],
            'deductions' => ['nullable', 'array'],
            'effective_from' => ['required', 'date'],
        ]);

        $comp = $this->payroll->setCompensation(
            (int) $request->user()->business_id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $comp], 201);
    }

    public function indexPayRuns(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->payroll->listPayRuns((int) $request->user()->business_id),
        ]);
    }

    public function storePayRun(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date'],
        ]);

        $payRun = $this->payroll->createPayRun(
            (int) $request->user()->business_id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $payRun], 201);
    }

    public function showPayRun(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->payroll->findPayRunOrFail((int) $request->user()->business_id, $id),
        ]);
    }

    public function calculatePayRun(Request $request, int $id): JsonResponse
    {
        $payRun = $this->payroll->calculatePayRun(
            (int) $request->user()->business_id,
            $id,
            $request->user()->id,
        );

        return response()->json(['data' => $payRun]);
    }

    public function approvePayRun(Request $request, int $id): JsonResponse
    {
        $payRun = $this->payroll->approvePayRun(
            (int) $request->user()->business_id,
            $id,
            $request->user()->id,
        );

        return response()->json(['data' => $payRun]);
    }

    public function postPayRun(Request $request, int $id): JsonResponse
    {
        $payRun = $this->payroll->postPayRun(
            (int) $request->user()->business_id,
            $id,
            $request->user()->id,
        );

        return response()->json(['data' => $payRun]);
    }
}
