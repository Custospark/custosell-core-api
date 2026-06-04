<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Shift;
use App\Services\Contracts\UserServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(
        protected UserServiceInterface $userService,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->userService->register($request->validated());
        $user->load('business');

        $activeShift = Shift::create([
            'business_id' => $user->business_id,
            'user_id' => $user->id,
            'clock_in' => now(),
            'status' => 'active',
        ]);

        $user->setRelation('activeShift', $activeShift);
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->userService->login($request->email, $request->password);

        if (!$user) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user->load('business');

        // Find active shift or create a new one
        $activeShift = Shift::where('business_id', $user->business_id)
            ->where('user_id', $user->id)
            ->whereNull('clock_out')
            ->where('status', 'active')
            ->first();

        if (!$activeShift) {
            $activeShift = Shift::create([
                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'clock_in' => now(),
                'status' => 'active',
            ]);
        }

        $user->setRelation('activeShift', $activeShift);
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): UserResource
    {
        $user = $request->user()->load(['role', 'business']);

        $activeShift = Shift::where('business_id', $user->business_id)
            ->where('user_id', $user->id)
            ->whereNull('clock_out')
            ->where('status', 'active')
            ->first();

        if ($activeShift) {
            $user->setRelation('activeShift', $activeShift);
        }

        return new UserResource($user);
    }
}
