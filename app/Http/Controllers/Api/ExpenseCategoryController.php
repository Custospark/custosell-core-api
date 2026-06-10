<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseCategoryRequest;
use App\Http\Resources\ExpenseCategoryCollection;
use App\Http\Resources\ExpenseCategoryResource;
use App\Services\Contracts\ExpenseCategoryServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function __construct(
        protected ExpenseCategoryServiceInterface $expenseCategoryService,
    ) {}

    public function index(Request $request): ExpenseCategoryCollection
    {
        $businessId = $request->user()->business_id;
        return new ExpenseCategoryCollection($this->expenseCategoryService->getAll($businessId));
    }

    public function show(Request $request, int $id): ExpenseCategoryResource
    {
        $businessId = $request->user()->business_id;
        $expenseCategory = $this->expenseCategoryService->getByIdForBusiness($businessId, $id);
        if (!$expenseCategory) {
            abort(404, 'Expense category not found');
        }
        return new ExpenseCategoryResource($expenseCategory);
    }

    public function store(ExpenseCategoryRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $expenseCategory = $this->expenseCategoryService->create($businessId, $request->validated());
        return response()->json(new ExpenseCategoryResource($expenseCategory), 201);
    }

    public function update(ExpenseCategoryRequest $request, int $id): ExpenseCategoryResource
    {
        $businessId = $request->user()->business_id;
        $expenseCategory = $this->expenseCategoryService->update($businessId, $id, $request->validated());
        return new ExpenseCategoryResource($expenseCategory);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $this->expenseCategoryService->delete($businessId, $id);
        return response()->json(null, 204);
    }
}
