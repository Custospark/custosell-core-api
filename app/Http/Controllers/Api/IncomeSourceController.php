<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IncomeSourceRequest;
use App\Http\Resources\IncomeSourceCollection;
use App\Http\Resources\IncomeSourceResource;
use App\Services\Contracts\IncomeSourceServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncomeSourceController extends Controller
{
    public function __construct(
        protected IncomeSourceServiceInterface $incomeSourceService,
    ) {}

    public function index(Request $request): IncomeSourceCollection
    {
        $businessId = $request->user()->business_id;
        $filters = $request->only(['source_name', 'date_from', 'date_to']);
        return new IncomeSourceCollection(
            $this->incomeSourceService->getAll($businessId, $filters)
        );
    }

    public function show(int $id): IncomeSourceResource
    {
        $incomeSource = $this->incomeSourceService->getById($id);
        if (!$incomeSource) {
            abort(404, 'Income source not found');
        }
        return new IncomeSourceResource($incomeSource);
    }

    public function store(IncomeSourceRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $userId = $request->user()->id;
        $incomeSource = $this->incomeSourceService->create($businessId, $userId, $request->validated());
        return response()->json(new IncomeSourceResource($incomeSource), 201);
    }

    public function update(IncomeSourceRequest $request, int $id): IncomeSourceResource
    {
        $incomeSource = $this->incomeSourceService->update($id, $request->validated());
        return new IncomeSourceResource($incomeSource);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->incomeSourceService->delete($id);
        return response()->json(null, 204);
    }

    public function summary(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $filters = $request->only(['date_from', 'date_to', 'source_name']);
        $summary = $this->incomeSourceService->getSummary($businessId, $filters);
        return response()->json($summary);
    }
}
