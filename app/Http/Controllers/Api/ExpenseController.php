<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseRequest;
use App\Http\Resources\ExpenseCollection;
use App\Http\Resources\ExpenseResource;
use App\Services\Contracts\ExpenseServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function __construct(
        protected ExpenseServiceInterface $expenseService,
    ) {}

    public function index(Request $request): ExpenseCollection
    {
        $businessId = $request->user()->business_id;
        return new ExpenseCollection($this->expenseService->getAll($businessId));
    }

    public function show(int $id): ExpenseResource
    {
        $expense = $this->expenseService->getById($id);
        if (!$expense) {
            abort(404, 'Expense not found');
        }
        return new ExpenseResource($expense);
    }

    public function store(ExpenseRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $data = $request->validated();
        $data['recorded_by'] = $request->user()->id;
        $expense = $this->expenseService->create($businessId, $data);
        return response()->json(new ExpenseResource($expense), 201);
    }

    public function update(ExpenseRequest $request, int $id): ExpenseResource
    {
        $expense = $this->expenseService->update($id, $request->validated());
        return new ExpenseResource($expense);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->expenseService->delete($id);
        return response()->json(null, 204);
    }
}
