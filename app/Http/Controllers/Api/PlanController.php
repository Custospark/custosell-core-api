<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlanRequest;
use App\Http\Resources\PlanCollection;
use App\Http\Resources\PlanResource;
use App\Services\Contracts\PlanServiceInterface;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
    public function __construct(
        protected PlanServiceInterface $planService,
    ) {}

    public function index(): PlanCollection
    {
        return new PlanCollection($this->planService->getAll());
    }

    public function show(int $id): PlanResource
    {
        $plan = $this->planService->getById($id);
        if (!$plan) {
            abort(404, 'Plan not found');
        }
        return new PlanResource($plan);
    }

    public function store(PlanRequest $request): JsonResponse
    {
        $plan = $this->planService->create($request->validated());
        return response()->json(new PlanResource($plan), 201);
    }

    public function update(PlanRequest $request, int $id): PlanResource
    {
        $plan = $this->planService->update($id, $request->validated());
        return new PlanResource($plan);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->planService->delete($id);
        return response()->json(null, 204);
    }
}
