<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessRegisterRequest;
use App\Http\Requests\BusinessRequest;
use App\Http\Requests\BusinessStorefrontProfileRequest;
use App\Http\Requests\BusinessSupplyProfileRequest;
use App\Http\Resources\BusinessResource;
use App\Services\Contracts\BusinessServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        $business = $this->businessService->getForUser($request->user());
        if (! $business) {
            abort(404, 'No business found for this user');
        }

        return new BusinessResource($business);
    }

    public function store(BusinessRegisterRequest $request): JsonResponse
    {
        $userData = [
            'name' => $request->input('owner_name'),
            'email' => $request->input('email'),
            'password' => $request->input('password'),
        ];
        $businessData = $request->except(['password', 'password_confirmation', 'owner_name']);
        $business = $this->businessService->register($userData, $businessData);
        $business->load('subscription.plan');
        return response()->json(new BusinessResource($business), 201);
    }

    public function update(BusinessRequest $request, int $id): BusinessResource
    {
        $business = $this->businessService->update($id, $request->validated());
        return new BusinessResource($business);
    }

    public function updateProfile(BusinessRequest $request): BusinessResource
    {
        $business = $this->businessService->getForUser($request->user());
        if (! $business) {
            abort(404, 'Business not found');
        }

        $data = $request->validated();

        if ($request->hasFile('logo')) {
            if ($business->logo_path) {
                $oldPath = str_replace('/storage/', '', $business->logo_path);
                Storage::disk('public')->delete($oldPath);
            }
            $data['logo_path'] = '/storage/' . $request->file('logo')->store('business-logos', 'public');
        }

        $business = $this->businessService->update($business->id, $data);

        return new BusinessResource($business);
    }

    public function settings(Request $request): BusinessResource
    {
        $business = $this->businessService->getForUser($request->user());
        if (! $business) {
            abort(404, 'Business not found');
        }

        return new BusinessResource($business);
    }

    public function updateSettings(BusinessRequest $request): BusinessResource
    {
        $business = $this->businessService->getForUser($request->user());
        if (! $business) {
            abort(404, 'Business not found');
        }
        $business = $this->businessService->updateSettings($business->id, $request->validated());

        return new BusinessResource($business);
    }

    public function updateSupplyProfile(BusinessSupplyProfileRequest $request): BusinessResource
    {
        $business = $this->businessService->getForUser($request->user());
        if (! $business) {
            abort(404, 'Business not found');
        }

        $business = $this->businessService->updateSupplyProfile($business->id, $request->validated());

        return new BusinessResource($business);
    }

    public function updateStorefrontProfile(BusinessStorefrontProfileRequest $request): BusinessResource
    {
        $business = $this->businessService->getForUser($request->user());
        if (! $business) {
            abort(404, 'Business not found');
        }

        $business = $this->businessService->updateStorefrontProfile($business->id, $request->validated());

        return new BusinessResource($business);
    }

    public function slugAvailable(Request $request): JsonResponse
    {
        $business = $this->businessService->getForUser($request->user());
        $slug = (string) $request->query('slug', '');
        $result = $this->businessService->checkSlugAvailability($slug, $business?->id);

        return response()->json($result);
    }
}
