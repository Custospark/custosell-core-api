<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\PasswordChangeRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\SendVerificationCodeRequest;
use App\Http\Requests\VerifyCodeRequest;
use App\Http\Resources\UserResource;
use App\Mail\StandardEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use App\Models\Shift;
use App\Models\User;
use App\Services\Contracts\AccountAuditLogServiceInterface;
use App\Services\Contracts\AccountVerificationServiceInterface;
use App\Services\Contracts\UserServiceInterface;
use App\Services\Contracts\SubscriptionStateMachineServiceInterface;
use App\Services\Platform\PlatformAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(
        protected UserServiceInterface $userService,
        protected PlatformAdminService $platformAdminService,
        protected SubscriptionStateMachineServiceInterface $subscriptionStateMachine,
        protected AccountVerificationServiceInterface $verificationService,
        protected AccountAuditLogServiceInterface $auditLogService,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->userService->register($request->validated());
        $this->platformAdminService->assignIfEligible($user);
        $user->load(['business.subscription.plan', 'role', 'roles', 'location', 'locations']);

        if ($user->business_id && $user->business?->business_type !== 'personal') {
            $activeShift = Shift::create([
                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'clock_in' => now(),
                'status' => 'active',
            ]);
            $user->setRelation('activeShift', $activeShift);
        } else {
            $user->setRelation('activeShift', null);
        }

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

        if (config('auth.verification.required') && !$user->email_verified_at) {
            $this->verificationService->issue(
                $user,
                AccountVerificationServiceInterface::PURPOSE_EMAIL_VERIFICATION,
                $request->ip(),
                $request->userAgent(),
            );

            return response()->json([
                'message' => 'Please verify your email address to continue.',
                'requires_email_verification' => true,
                'email' => $user->email,
            ], 403);
        }

        if ($user->two_factor_enabled) {
            $this->verificationService->issue(
                $user,
                AccountVerificationServiceInterface::PURPOSE_TWO_FACTOR,
                $request->ip(),
                $request->userAgent(),
            );
            $this->auditLogService->log($user, 'two_factor_challenge', [], $request->ip(), $request->userAgent());

            return response()->json([
                'message' => 'Enter the security code sent to your email to finish signing in.',
                'requires_two_factor' => true,
                'email' => $user->email,
            ], 403);
        }

        return $this->authResponse($request, $user);
    }

    public function sendVerificationCode(SendVerificationCodeRequest $request): JsonResponse
    {
        $user = $this->userService->findByEmail($request->email);

        if (!$user) {
            return response()->json(['message' => 'Invalid credentials'], 404);
        }

        if ($request->purpose === AccountVerificationServiceInterface::PURPOSE_EMAIL_VERIFICATION && $user->email_verified_at) {
            return response()->json(['message' => 'Your email is already verified.'], 422);
        }

        $this->verificationService->issue(
            $user,
            $request->purpose,
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json(['message' => 'If that email address is associated with an account, a security code has been sent.']);
    }

    public function verify(VerifyCodeRequest $request): JsonResponse
    {
        $user = $this->userService->findByEmail($request->email);

        if (!$user) {
            return response()->json(['message' => 'That security code is invalid or has expired.'], 422);
        }

        if ($request->purpose === AccountVerificationServiceInterface::PURPOSE_EMAIL_VERIFICATION) {
            return $this->verifyEmail($request, $user);
        }

        if (!$user->two_factor_enabled) {
            return response()->json(['message' => 'Two-factor authentication is not enabled on this account.'], 422);
        }

        if (!$this->verificationService->verify($user, $request->purpose, $request->code)) {
            return response()->json(['message' => 'That security code is invalid or has expired.'], 422);
        }

        $this->auditLogService->log($user, 'two_factor_passed', [], $request->ip(), $request->userAgent());

        return $this->authResponse($request, $user->refresh());
    }

    private function verifyEmail(Request $request, User $user): JsonResponse
    {
        if ($user->email_verified_at) {
            return response()->json(['message' => 'Your email is already verified.'], 422);
        }

        if (!$this->verificationService->verify($user, AccountVerificationServiceInterface::PURPOSE_EMAIL_VERIFICATION, $request->code)) {
            return response()->json(['message' => 'That security code is invalid or has expired.'], 422);
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->auditLogService->log($user, 'email_verified', [], $request->ip(), $request->userAgent());

        // Email verification only opens the account — if 2FA is enabled, the
        // sign-in still needs the second factor before completing.
        if ($user->refresh()->two_factor_enabled) {
            $this->verificationService->issue($user, AccountVerificationServiceInterface::PURPOSE_TWO_FACTOR, $request->ip(), $request->userAgent());
            $this->auditLogService->log($user, 'two_factor_challenge', [], $request->ip(), $request->userAgent());

            return response()->json([
                'message' => 'Enter the security code sent to your email to finish signing in.',
                'requires_two_factor' => true,
                'email' => $user->email,
            ], 403);
        }

        return $this->authResponse($request, $user);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->auditLogService->log($user, 'logout', [], $request->ip(), $request->userAgent());
        $user->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    public function initiatePasswordChange(PasswordChangeRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Your current password is incorrect.'], 422);
        }

        $this->verificationService->issue(
            $user,
            AccountVerificationServiceInterface::PURPOSE_PASSWORD_CHANGE,
            $request->ip(),
            $request->userAgent(),
            ['password' => Hash::make($request->password)],
        );

        return response()->json([
            'message' => 'Enter the security code sent to your email to confirm the password change.',
            'requires_password_confirmation' => true,
        ]);
    }

    public function confirmPasswordChange(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'digits:6']]);

        $user = $request->user();
        $context = $this->verificationService->verify(
            $user,
            AccountVerificationServiceInterface::PURPOSE_PASSWORD_CHANGE,
            $request->code,
        );

        if ($context === null || empty($context['password'])) {
            return response()->json(['message' => 'That security code is invalid or has expired.'], 422);
        }

        $user->forceFill(['password' => $context['password']])->save();
        $this->auditLogService->log($user, 'password_changed', [], $request->ip(), $request->userAgent());

        Mail::to($user->email)->send(new StandardEmail(
            title: 'Your Custosell password was changed',
            mailBody: 'Your account password was changed successfully. If this was you, no further action is needed.',
            ctaLabel: 'Sign in to Custosell',
            ctaUrl: config('app.frontend_url', config('app.url')),
            tip: "This change was made from {$request->ip()}.",
        ));

        return response()->json(['message' => 'Your password has been updated.']);
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
        $user = $request->user()->load(['role', 'business.subscription.plan', 'business.subscription.referral.referralCode', 'roles', 'location', 'locations']);
        $this->reconcileSubscription($user);
        $this->platformAdminService->assignIfEligible($user);

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

    /**
     * Build the success auth payload (loads relations, reconciles subscription,
     * reopens the active shift, issues a token) shared by login and verify.
     */
    private function authResponse(Request $request, User $user): JsonResponse
    {
        $user->load(['business.subscription.plan', 'business.subscription.referral.referralCode', 'role', 'roles', 'location', 'locations']);
        $this->reconcileSubscription($user);
        $this->platformAdminService->assignIfEligible($user);

        if (! $this->platformAdminService->isPlatformAdmin($user) && $user->business_id) {
            $business = $user->business ?? \App\Models\Business::query()->select('id', 'status')->find($user->business_id);
            $blocked = config('platform.blocked_business_statuses', ['restricted', 'suspended']);
            if ($business && in_array($business->status, $blocked, true)) {
                $message = $business->status === 'suspended'
                    ? 'Your business account has been suspended.'
                    : 'Your business account has been restricted.';

                return response()->json(['message' => $message], 403);
            }
        }

        $user->forceFill(['last_login_at' => now()])->save();

        if ($user->business_id) {
            $user->business?->forceFill(['last_activity_at' => now()])->save();
        }

        if ($user->business_id) {
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
        } else {
            $user->setRelation('activeShift', null);
        }

        $this->auditLogService->log($user, 'login', ['via' => $request->purpose ?? 'password'], $request->ip(), $request->userAgent());

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    /**
     * Reconcile the subscription lifecycle on the auth path so the client
     * immediately resolves modules and access from the true status (trial →
     * past_due → suspended) instead of a stale persisted status.
     */
    private function reconcileSubscription($user): void
    {
        if (!$user->business_id) {
            return;
        }

        $subscription = $user->business?->subscription;
        if (!$subscription) {
            return;
        }

        $this->subscriptionStateMachine->processDueTransitions($subscription);

        $user->setRelation('business', $user->business->fresh()->load([
            'subscription.plan',
            'subscription.referral.referralCode',
        ]));
    }
}
