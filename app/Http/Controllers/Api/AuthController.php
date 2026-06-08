<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Password;
use App\Models\Shift;
use App\Services\Contracts\UserServiceInterface;
use App\Services\Platform\PlatformAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(
        protected UserServiceInterface $userService,
        protected PlatformAdminService $platformAdminService,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->userService->register($request->validated());
        $this->platformAdminService->assignIfEligible($user);
        $user->load(['business', 'roles']);

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

        if (!$user->is_active) {
            return response()->json(['message' => 'Your account has been deactivated.'], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $this->platformAdminService->assignIfEligible($user);
        $user->load(['business', 'roles']);

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

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink(
            $request->only('email')
        );

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['message' => 'If that email address is associated with an account, a password reset link has been sent.'])
            : response()->json(['message' => 'Unable to send password reset link.'], 500);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->password = bcrypt($password);
                $user->save();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Password has been reset successfully.'])
            : response()->json(['message' => 'Invalid or expired reset token.'], 400);
    }

    public function me(Request $request): UserResource
    {
        $user = $request->user()->load(['role', 'business', 'roles']);

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
