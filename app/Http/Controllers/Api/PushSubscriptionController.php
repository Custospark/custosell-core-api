<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WebPush\Contracts\WebPushServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function __construct(
        protected WebPushServiceInterface $webPush,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'enabled' => $this->webPush->isEnabled(),
                'subscription_count' => $this->webPush->countForUser($user->id),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', 'url'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        $this->webPush->register(
            $request->user()->id,
            $request->user()->business_id,
            $data,
        );

        return response()->json(['message' => 'Push subscription stored.'], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint', '');

        if ($endpoint === '') {
            return response()->json(['message' => 'Endpoint is required.'], 422);
        }

        $this->webPush->removeForEndpoint($request->user()->id, $endpoint);

        return response()->json(['message' => 'Push subscription removed.']);
    }
}