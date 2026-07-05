<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InventoryLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryLedgerController extends Controller
{
    public function __construct(
        protected InventoryLedgerService $inventoryLedgerService,
    ) {}

    public function reconciliation(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;

        return response()->json([
            'data' => $this->inventoryLedgerService->reconcile($businessId),
        ]);
    }

    public function postOpeningBalance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'force' => ['sometimes', 'boolean'],
        ]);

        $businessId = $request->user()->business_id;
        $userId = $request->user()->id;

        try {
            $result = $this->inventoryLedgerService->postOpeningBalance(
                $businessId,
                $userId,
                (bool) ($data['force'] ?? false),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }
}
