<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderRequest;
use App\Http\Resources\OrderCollection;
use App\Http\Resources\OrderResource;
use App\Services\Contracts\OrderServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        protected OrderServiceInterface $orderService,
    ) {}

    public function index(Request $request): OrderCollection
    {
        $businessId = $request->user()->business_id;
        $filters = $request->only(['status', 'q', 'source']);

        return new OrderCollection($this->orderService->getAll($businessId, $filters));
    }

    public function show(int $id): OrderResource
    {
        $order = $this->orderService->getById($id);
        if (!$order) {
            abort(404, 'Order not found');
        }

        return new OrderResource($order);
    }

    public function store(OrderRequest $request): JsonResponse
    {
        $order = $this->orderService->create(
            $request->user()->business_id,
            $request->user()->id,
            $request->validated(),
        );

        return response()->json(new OrderResource($order), 201);
    }

    public function update(OrderRequest $request, int $id): JsonResponse
    {
        $order = $this->orderService->update(
            $id,
            $request->user()->business_id,
            $request->validated(),
        );

        return response()->json(new OrderResource($order));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $order = $this->orderService->cancel($id, $request->user()->business_id);

        return response()->json(new OrderResource($order));
    }
}
