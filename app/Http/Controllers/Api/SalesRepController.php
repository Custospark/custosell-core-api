<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesRepRequest;
use App\Http\Resources\SalesRepCollection;
use App\Http\Resources\SalesRepResource;
use App\Services\Contracts\SalesRepServiceInterface;
use App\Services\Contracts\ReferralServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SalesRepController extends Controller
{
    public function __construct(
        protected SalesRepServiceInterface $salesRepService,
        protected ReferralServiceInterface $referralService,
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

    public function earningsIndex(): JsonResponse
    {
        try {
            $salesReps = $this->salesRepService->getWithEarnings();
            return response()->json(['data' => $salesReps]);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function earnings(int $id): JsonResponse
    {
        try {
            $earnings = $this->salesRepService->getEarnings($id);
            return response()->json($earnings);
        } catch (RuntimeException $e) {
            abort(404, $e->getMessage());
        }
    }

    public function myEarnings(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        try {
            $salesRep = $this->salesRepService->getByUser($user->id);
            if (!$salesRep) {
                return response()->json([
                    'message' => 'You are not a sales representative',
                    'is_sales_rep' => false,
                ]);
            }

            $earnings = $this->salesRepService->getEarnings($salesRep->id);
            $earnings['is_sales_rep'] = true;
            return response()->json($earnings);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }
}
