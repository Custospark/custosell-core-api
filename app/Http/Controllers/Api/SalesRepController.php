<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesRepRequest;
use App\Http\Resources\SalesRepCollection;
use App\Http\Resources\SalesRepResource;
use App\Services\Contracts\SalesRepServiceInterface;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class SalesRepController extends Controller
{
    public function __construct(
        protected SalesRepServiceInterface $salesRepService,
    ) {}

    public function index(): SalesRepCollection
    {
        return new SalesRepCollection($this->salesRepService->getAll());
    }

    public function show(int $id): SalesRepResource
    {
        $salesRep = $this->salesRepService->getById($id);
        if (!$salesRep) {
            abort(404, 'Sales rep not found');
        }
        return new SalesRepResource($salesRep);
    }

    public function store(SalesRepRequest $request): JsonResponse
    {
        $salesRep = $this->salesRepService->create($request->validated());
        return response()->json(new SalesRepResource($salesRep), 201);
    }

    public function update(SalesRepRequest $request, int $id): SalesRepResource
    {
        try {
            $salesRep = $this->salesRepService->update($id, $request->validated());
            return new SalesRepResource($salesRep);
        } catch (RuntimeException $e) {
            abort(404, $e->getMessage());
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->salesRepService->delete($id);
            return response()->json(['message' => 'Deleted'], 200);
        } catch (RuntimeException $e) {
            abort(404, $e->getMessage());
        }
    }
}
