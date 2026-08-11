<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocationRequest;
use App\Http\Resources\LocationCollection;
use App\Http\Resources\LocationResource;
use App\Services\Contracts\LocationServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function __construct(
        protected LocationServiceInterface $locationService,
    ) {}

    public function index(Request $request): LocationCollection
    {
        $businessId = $request->user()->business_id;
        return new LocationCollection($this->locationService->getAll($businessId));
    }

    public function active(Request $request): LocationCollection
    {
        $businessId = $request->user()->business_id;
        return new LocationCollection($this->locationService->getActive($businessId));
    }

    public function show(Request $request, int $id): LocationResource
    {
        $location = $this->locationService->getById($id);
        if (!$location || $location->business_id !== $request->user()->business_id) {
            abort(404, 'Location not found');
        }
        return new LocationResource($location);
    }

    public function store(LocationRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $location = $this->locationService->create($businessId, $request->validated());
        return response()->json(['data' => new LocationResource($location)], 201);
    }

    public function update(LocationRequest $request, int $id): LocationResource
    {
        $location = $this->locationService->update($id, $request->validated());
        return new LocationResource($location);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->locationService->delete($id);
        return response()->json(null, 204);
    }

    public function setDefault(int $id): LocationResource
    {
        $location = $this->locationService->setDefault($id);
        return new LocationResource($location);
    }
}
