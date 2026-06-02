<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubscriptionRequest;
use App\Http\Resources\SubscriptionCollection;
use App\Http\Resources\SubscriptionResource;
use App\Services\Contracts\SubscriptionServiceInterface;
use Illuminate\Http\JsonResponse;

class SubscriptionController extends Controller
{
    public function __construct(
        protected SubscriptionServiceInterface $subscriptionService,
    ) {}

    public function index(): SubscriptionCollection
    {
        return new SubscriptionCollection($this->subscriptionService->getAll());
    }

    public function show(int $id): SubscriptionResource
    {
        $subscription = $this->subscriptionService->getById($id);
        if (!$subscription) {
            abort(404, 'Subscription not found');
        }
        return new SubscriptionResource($subscription);
    }

    public function store(SubscriptionRequest $request): JsonResponse
    {
        $subscription = $this->subscriptionService->create($request->validated());
        return response()->json(new SubscriptionResource($subscription), 201);
    }

    public function update(SubscriptionRequest $request, int $id): SubscriptionResource
    {
        $subscription = $this->subscriptionService->update($id, $request->validated());
        return new SubscriptionResource($subscription);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->subscriptionService->delete($id);
        return response()->json(null, 204);
    }
}
