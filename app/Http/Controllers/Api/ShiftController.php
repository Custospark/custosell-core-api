<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShiftRequest;
use App\Http\Resources\ShiftCollection;
use App\Http\Resources\ShiftResource;
use App\Services\Contracts\ShiftServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function __construct(
        protected ShiftServiceInterface $shiftService,
    ) {}

    public function index(Request $request): ShiftCollection
    {
        $businessId = $request->user()->business_id;
        return new ShiftCollection($this->shiftService->getAll($businessId));
    }

    public function show(int $id): ShiftResource
    {
        $shift = $this->shiftService->getById($id);
        if (!$shift) {
            abort(404, 'Shift not found');
        }
        return new ShiftResource($shift);
    }

    public function store(ShiftRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $userId = $request->user()->id;
        $shift = $this->shiftService->create($businessId, $userId, $request->validated());
        return response()->json(new ShiftResource($shift), 201);
    }

    public function update(ShiftRequest $request, int $id): ShiftResource
    {
        $shift = $this->shiftService->update($id, $request->validated());
        return new ShiftResource($shift);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->shiftService->delete($id);
        return response()->json(null, 204);
    }

    public function active(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $userId = $request->user()->id;
        $shift = $this->shiftService->getActiveByUser($businessId, $userId);
        if (!$shift) {
            return response()->json(null, 204);
        }
        return response()->json(new ShiftResource($shift));
    }
}
