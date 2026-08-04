<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessSocialLinkRequest;
use App\Http\Resources\BusinessSocialLinkCollection;
use App\Http\Resources\BusinessSocialLinkResource;
use App\Models\BusinessSocialLink;
use App\Services\Contracts\BusinessSocialLinkServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessSocialLinkController extends Controller
{
    public function __construct(
        protected BusinessSocialLinkServiceInterface $linkService,
    ) {}

    public function index(Request $request): BusinessSocialLinkCollection
    {
        $businessId = $request->user()->business_id;
        return new BusinessSocialLinkCollection($this->linkService->getAll($businessId));
    }

    public function show(Request $request, int $id): BusinessSocialLinkResource|JsonResponse
    {
        $link = $this->linkService->getById($id);
        if (!$link || $link->business_id !== $request->user()->business_id) {
            return response()->json(['message' => 'Social link not found'], 404);
        }
        return new BusinessSocialLinkResource($link);
    }

    public function store(BusinessSocialLinkRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $link = $this->linkService->create($businessId, $request->validated());
        return response()->json(['data' => new BusinessSocialLinkResource($link)], 201);
    }

    public function update(BusinessSocialLinkRequest $request, int $id): BusinessSocialLinkResource|JsonResponse
    {
        $link = $this->linkService->getById($id);
        if (!$link || $link->business_id !== $request->user()->business_id) {
            return response()->json(['message' => 'Social link not found'], 404);
        }
        $link = $this->linkService->update($id, $request->validated());
        return new BusinessSocialLinkResource($link);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $link = $this->linkService->getById($id);
        if (!$link || $link->business_id !== $request->user()->business_id) {
            return response()->json(['message' => 'Social link not found'], 404);
        }
        $this->linkService->delete($id);
        return response()->json(null, 204);
    }
}