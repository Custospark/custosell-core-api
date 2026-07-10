<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\HrEmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrEmployeeController extends Controller
{
    public function __construct(
        protected HrEmployeeService $employees,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'department_id' => ['nullable', 'integer'],
            'employment_type' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $paginator = $this->employees->list(
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

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->employees->findOrFail((int) $request->user()->business_id, $id),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_number' => ['required', 'string', 'max:64'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'position_id' => ['nullable', 'integer'],
            'manager_employee_id' => ['nullable', 'integer'],
            'employment_type' => ['nullable', 'in:full_time,part_time,contract,casual'],
            'status' => ['nullable', 'in:onboarding,active,on_leave,terminated'],
            'hire_date' => ['nullable', 'date'],
            'termination_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $employee = $this->employees->create(
            (int) $request->user()->business_id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $employee], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'employee_number' => ['sometimes', 'string', 'max:64'],
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'department_id' => ['nullable', 'integer'],
            'position_id' => ['nullable', 'integer'],
            'manager_employee_id' => ['nullable', 'integer'],
            'employment_type' => ['nullable', 'in:full_time,part_time,contract,casual'],
            'status' => ['nullable', 'in:onboarding,active,on_leave,terminated'],
            'hire_date' => ['nullable', 'date'],
            'termination_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $employee = $this->employees->update(
            (int) $request->user()->business_id,
            $id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $employee]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->employees->delete((int) $request->user()->business_id, $id, $request->user()->id);

        return response()->json(null, 204);
    }

    public function linkUser(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $employee = $this->employees->linkUser(
            (int) $request->user()->business_id,
            $id,
            (int) $validated['user_id'],
            $request->user()->id,
        );

        return response()->json(['data' => $employee]);
    }

    public function unlinkUser(Request $request, int $id): JsonResponse
    {
        $employee = $this->employees->unlinkUser(
            (int) $request->user()->business_id,
            $id,
            $request->user()->id,
        );

        return response()->json(['data' => $employee]);
    }
}
