<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessRequest;
use App\Http\Resources\BusinessResource;
use App\Services\Contracts\BusinessServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    public function __construct(
        protected BusinessServiceInterface $businessService,
    ) {}

    public function show(int $id): BusinessResource
    {
        $business = $this->businessService->getById($id);
        if (!$business) {
            abort(404, 'Business not found');
        }
        return new BusinessResource($business);
    }

    public function mine(Request $request): BusinessResource
    {
        $business = $this->businessService->getByOwner($request->user()->id);
        if (!$business) {
            abort(404, 'No business found for this user');
        }
        return new BusinessResource($business);
    }

    public function store(BusinessRequest $request): JsonResponse
    {
        $userData = $request->only(['name', 'email', 'password']);
        $businessData = $request->except(['password', 'password_confirmation']);
        $business = $this->businessService->register($userData, $businessData);
        return response()->json(new BusinessResource($business), 201);
    }

    public function update(BusinessRequest $request, int $id): BusinessResource
    {
        $business = $this->businessService->update($id, $request->validated());
        return new BusinessResource($business);
    }

    public function settings(Request $request): BusinessResource
    {
        $business = $request->user()->business;
        if (!$business) {
            abort(404, 'Business not found');
        }
        return new BusinessResource($business);
    }

    public function updateSettings(BusinessRequest $request): BusinessResource
    {
        $business = $request->user()->business;
        if (!$business) {
            abort(404, 'Business not found');
        }
        $business = $this->businessService->updateSettings($business->id, $request->validated());
        return new BusinessResource($business);
    }
}
