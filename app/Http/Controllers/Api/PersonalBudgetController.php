<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PersonalBudgetRequest;
use App\Http\Resources\PersonalBudgetResource;
use App\Services\Contracts\PersonalBudgetServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonalBudgetController extends Controller
{
    public function __construct(
        protected PersonalBudgetServiceInterface $personalBudgetService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $filters = $request->only(['status', 'date_from', 'date_to']);
        return response()->json(
            $this->personalBudgetService->getAll($businessId, $filters)
        );
    }

    public function show(int $id): JsonResponse
    {
        $budget = $this->personalBudgetService->getById($id);
        if (!$budget) {
            abort(404, 'Budget not found');
        }
        return response()->json(['data' => new PersonalBudgetResource($budget)]);
    }

    public function store(PersonalBudgetRequest $request): JsonResponse
    {
        $budget = $this->personalBudgetService->create(
            $request->user()->business_id,
            $request->user()->id,
            $request->validated()
        );
        return response()->json(['data' => new PersonalBudgetResource($budget)], 201);
    }

    public function update(PersonalBudgetRequest $request, int $id): JsonResponse
    {
        $budget = $this->personalBudgetService->update($id, $request->validated());
        return response()->json(['data' => new PersonalBudgetResource($budget)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->personalBudgetService->delete($id);
        return response()->json(null, 204);
    }
}