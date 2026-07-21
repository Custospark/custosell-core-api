<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Services\Payment\GatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GatewayWebhookController extends Controller
{
    public function __construct(
        protected GatewayService $gatewayService,
    ) {}

    public function webhook(string $gateway, Request $request): JsonResponse
    {
        $this->gatewayService->processWebhook($gateway, $request);

        return response()->json(['success' => true]);
    }

    public function callback(string $gateway, Request $request): JsonResponse
    {
        $result = $this->gatewayService->processCallback($gateway, $request);

        return response()->json([
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? 'Callback processed.',
        ]);
    }
}
