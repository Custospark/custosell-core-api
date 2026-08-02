<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StaffTransferRequest;
use App\Http\Resources\StaffTransferCollection;
use App\Http\Resources\StaffTransferResource;
use App\Services\Contracts\StaffTransferServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffTransferController extends Controller
{
    public function __construct(
        protected StaffTransferServiceInterface $staffTransferService,
    ) {}

    public function index(Request $request): StaffTransferCollection
    {
        return new StaffTransferCollection(
            $this->staffTransferService->getAll($request->user()->business_id),
        );
    }

    public function show(Request $request, int $id): StaffTransferResource
    {
        $transfer = $this->staffTransferService->getByIdForBusiness($id, $request->user()->business_id);
        if (!$transfer) {
            abort(404, 'Staff transfer not found');
        }
        return new StaffTransferResource($transfer);
    }

    public function store(StaffTransferRequest $request): JsonResponse
    {
        $transfer = $this->staffTransferService->transfer(
            $request->user()->business_id,
            $request->user()->id,
            $request->validated(),
        );

        return response()->json(['data' => new StaffTransferResource($transfer)], 201);
    }
}
