<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __construct(
        protected OnboardingService $onboarding,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('business');

        return response()->json([
            'data' => $this->onboarding->payloadFor($user),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string'],
            'primary_intent' => ['nullable', 'string', 'max:64'],
            'secondary_intent' => ['nullable', 'string', 'max:64'],
            'tour_step' => ['nullable', 'integer', 'min:0', 'max:50'],
        ]);

        $user = $request->user()->loadMissing('business');
        $payload = $this->onboarding->update($user, $data);

        return response()->json([
            'data' => $payload,
            'user' => new UserResource($user->fresh(['business', 'role', 'roles'])),
        ]);
    }
}
