<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseOrderReceiveRequest;
use App\Http\Requests\PurchaseOrderRejectRequest;
use App\Http\Requests\PurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderCollection;
use App\Http\Resources\PurchaseOrderResource;
use App\Services\Contracts\PurchaseOrderServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(
        protected PurchaseOrderServiceInterface $purchaseOrderService,
    ) {}

    public function index(Request $request): PurchaseOrderCollection
    {
        $businessId = $request->user()->business_id;
        $filters = $request->only(['status']);

        return new PurchaseOrderCollection($this->purchaseOrderService->getAllForBuyer($businessId, $filters));
    }

    public function store(PurchaseOrderRequest $request): JsonResponse
    {
        $po = $this->purchaseOrderService->create(
            $request->user()->business_id,
            $request->user()->id,
            $request->validated(),
        );

        return response()->json(new PurchaseOrderResource($po), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $po = $this->purchaseOrderService->getVisibleForBusiness($id, $businessId);

        if (! $po) {
            abort(404, 'Purchase order not found');
        }

        return response()->json(new PurchaseOrderResource($po));
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $po = $this->purchaseOrderService->submit($id, $request->user()->business_id);

        return response()->json(new PurchaseOrderResource($po));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $po = $this->purchaseOrderService->cancel($id, $request->user()->business_id);

        return response()->json(new PurchaseOrderResource($po));
    }

    public function incoming(Request $request): PurchaseOrderCollection
    {
        $businessId = $request->user()->business_id;
        $filters = $request->only(['status']);

        return new PurchaseOrderCollection($this->purchaseOrderService->getIncomingForSeller($businessId, $filters));
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        $po = $this->purchaseOrderService->accept($id, $request->user()->business_id);

        return response()->json(new PurchaseOrderResource($po));
    }

    public function reject(PurchaseOrderRejectRequest $request, int $id): JsonResponse
    {
        $po = $this->purchaseOrderService->reject(
            $id,
            $request->user()->business_id,
            $request->validated('rejection_reason'),
        );

        return response()->json(new PurchaseOrderResource($po));
    }

    public function fulfill(Request $request, int $id): JsonResponse
    {
        $po = $this->purchaseOrderService->fulfill($id, $request->user()->business_id, $request->user()->id);

        return response()->json(new PurchaseOrderResource($po));
    }

    public function receive(PurchaseOrderReceiveRequest $request, int $id): JsonResponse
    {
        $po = $this->purchaseOrderService->receive(
            $id,
            $request->user()->business_id,
            $request->user()->id,
            $request->validated('items'),
        );

        return response()->json(new PurchaseOrderResource($po));
    }
}
