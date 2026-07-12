<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\StorefrontPlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Services\Storefront\StorefrontService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StorefrontController
{
    public function __construct(
        private readonly StorefrontService $storefront,
    ) {}

    public function myOrders(Request $request): JsonResponse
    {
        $userId = (int) Auth::id();
        $paginator = $this->storefront->myOrders(
            $userId,
            $request->query('status'),
            $request->query('q'),
            (int) $request->query('per_page', 24),
        );

        $data = collect($paginator->items())->map(
            fn ($order) => $this->storefront->buyerOrderPayload($order)
        )->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function shops(Request $request): JsonResponse
    {
        $paginator = $this->storefront->discoverShops(
            $request->query('q'),
            (int) $request->query('per_page', 24),
        );

        $data = collect($paginator->items())->map(
            fn ($business) => $this->storefront->publicShopPayload($business)
        )->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function discover(Request $request): JsonResponse
    {
        $viewerId = Auth::guard('sanctum')->id();
        $paginator = $this->storefront->discoverProducts(
            $request->query('q'),
            $request->query('category'),
            (int) $request->query('per_page', 24),
            $viewerId ? (int) $viewerId : null,
        );

        $viewer = $viewerId ? (int) $viewerId : null;
        $data = collect($paginator->items())->map(
            fn ($product) => $this->storefront->publicProductPayload($product, $viewer)
        )->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function categories(): JsonResponse
    {
        return response()->json([
            'data' => $this->storefront->discoverCategories()->values(),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $business = $this->storefront->findEnabledShop($slug);

        return response()->json($this->storefront->publicShopPayload($business));
    }

    public function products(Request $request, string $slug): JsonResponse
    {
        $business = $this->storefront->findEnabledShop($slug);
        $viewerId = Auth::guard('sanctum')->id();
        $viewer = $viewerId ? (int) $viewerId : null;
        $products = $this->storefront->shopProducts($business, $request->query('category'), $viewer);

        return response()->json([
            'data' => $products->map(fn ($p) => $this->storefront->publicProductPayload($p, $viewer))->values(),
            'shop' => $this->storefront->publicShopPayload($business),
        ]);
    }

    public function rateProduct(Request $request, string $slug, int $product): JsonResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        $payload = $this->storefront->rateProduct(
            $slug,
            $product,
            (int) Auth::id(),
            (int) $validated['rating'],
        );

        return response()->json([
            'message' => 'Thanks for your rating!',
            'data' => $payload,
        ]);
    }

    public function placeOrder(StorefrontPlaceOrderRequest $request, string $slug): JsonResponse
    {
        $payload = $request->validated();
        $buyer = $request->user('sanctum');
        if ($buyer) {
            $payload['storefront_buyer_user_id'] = (int) $buyer->id;
        }

        $order = $this->storefront->placeOrder($slug, $payload);

        return response()->json([
            'message' => 'Order received. The shop will contact you shortly.',
            'order_number' => $order->order_number,
            'total_amount' => $order->total_amount,
            'order' => new OrderResource($order),
        ], 201);
    }
}
