<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\HrOrgService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrOrgController extends Controller
{
    public function __construct(
        protected HrOrgService $org,
    ) {}

    public function indexDepartments(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        return response()->json(['data' => $this->org->listDepartments($businessId)]);
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $department = $this->org->createDepartment(
            (int) $request->user()->business_id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $department], 201);
    }

    public function updateDepartment(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $department = $this->org->updateDepartment(
            (int) $request->user()->business_id,
            $id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $department]);
    }

    public function destroyDepartment(Request $request, int $id): JsonResponse
    {
        $this->org->deleteDepartment((int) $request->user()->business_id, $id, $request->user()->id);

        return response()->json(null, 204);
    }

    public function indexPositions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['nullable', 'integer'],
        ]);

        $positions = $this->org->listPositions(
            (int) $request->user()->business_id,
            isset($validated['department_id']) ? (int) $validated['department_id'] : null,
        );

        return response()->json(['data' => $positions]);
    }

    public function storePosition(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'department_id' => ['nullable', 'integer'],
        ]);

        $position = $this->org->createPosition(
            (int) $request->user()->business_id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $position], 201);
    }

    public function updatePosition(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'department_id' => ['nullable', 'integer'],
        ]);

        $position = $this->org->updatePosition(
            (int) $request->user()->business_id,
            $id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $position]);
    }

    public function destroyPosition(Request $request, int $id): JsonResponse
    {
        $this->org->deletePosition((int) $request->user()->business_id, $id, $request->user()->id);

        return response()->json(null, 204);
    }
}
