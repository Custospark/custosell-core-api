<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\HrLeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrLeaveController extends Controller
{
    public function __construct(
        protected HrLeaveService $leave,
    ) {}

    public function indexTypes(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->leave->listTypes((int) $request->user()->business_id),
        ]);
    }

    public function storeType(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:32'],
            'paid' => ['nullable', 'boolean'],
            'days_per_year' => ['nullable', 'numeric', 'min:0'],
            'requires_approval' => ['nullable', 'boolean'],
        ]);

        $type = $this->leave->createType(
            (int) $request->user()->business_id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $type], 201);
    }

    public function updateType(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => ['sometimes', 'string', 'max:32'],
            'paid' => ['nullable', 'boolean'],
            'days_per_year' => ['nullable', 'numeric', 'min:0'],
            'requires_approval' => ['nullable', 'boolean'],
        ]);

        $type = $this->leave->updateType(
            (int) $request->user()->business_id,
            $id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $type]);
    }

    public function destroyType(Request $request, int $id): JsonResponse
    {
        $this->leave->deleteType((int) $request->user()->business_id, $id, $request->user()->id);

        return response()->json(null, 204);
    }

    public function indexBalances(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $this->leave->listBalances(
                (int) $request->user()->business_id,
                isset($validated['employee_id']) ? (int) $validated['employee_id'] : null,
                isset($validated['year']) ? (int) $validated['year'] : null,
            ),
        ]);
    }

    public function ensureBalance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'leave_type_id' => ['required', 'integer'],
            'year' => ['nullable', 'integer'],
        ]);

        $balance = $this->leave->ensureBalance(
            (int) $request->user()->business_id,
            (int) $validated['employee_id'],
            (int) $validated['leave_type_id'],
            isset($validated['year']) ? (int) $validated['year'] : null,
        );

        return response()->json(['data' => $balance], 201);
    }

    public function indexRequests(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $this->leave->listRequests((int) $request->user()->business_id, $validated),
        ]);
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'leave_type_id' => ['required', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date'],
            'days' => ['nullable', 'numeric', 'min:0.5'],
            'reason' => ['nullable', 'string'],
        ]);

        $leaveRequest = $this->leave->requestLeave(
            (int) $request->user()->business_id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $leaveRequest], 201);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'review_note' => ['nullable', 'string'],
        ]);

        $leaveRequest = $this->leave->approve(
            (int) $request->user()->business_id,
            $id,
            $request->user()->id,
            $validated['review_note'] ?? null,
        );

        return response()->json(['data' => $leaveRequest]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'review_note' => ['nullable', 'string'],
        ]);

        $leaveRequest = $this->leave->reject(
            (int) $request->user()->business_id,
            $id,
            $request->user()->id,
            $validated['review_note'] ?? null,
        );

        return response()->json(['data' => $leaveRequest]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $leaveRequest = $this->leave->cancel(
            (int) $request->user()->business_id,
            $id,
            $request->user()->id,
        );

        return response()->json(['data' => $leaveRequest]);
    }
}
