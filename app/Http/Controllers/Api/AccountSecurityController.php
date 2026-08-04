<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ToggleTwoFactorRequest;
use App\Services\Contracts\AccountAuditLogServiceInterface;
use App\Services\Contracts\AccountVerificationServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountSecurityController extends Controller
{
    public function __construct(
        protected AccountVerificationServiceInterface $verificationService,
        protected AccountAuditLogServiceInterface $auditLogService,
    ) {}

    public function toggleTwoFactor(ToggleTwoFactorRequest $request): JsonResponse
    {
        $user = $request->user();
        $enabled = (bool) $request->boolean('enabled');

        $user->forceFill(['two_factor_enabled' => $enabled])->save();

        $this->auditLogService->log(
            $user,
            $enabled ? 'two_factor_enabled' : 'two_factor_disabled',
            [],
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'message' => $enabled
                ? 'Two-factor authentication is now enabled. A security code will be required at sign-in.'
                : 'Two-factor authentication has been disabled.',
            'two_factor_enabled' => $enabled,
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->auditLogService->feed($request->user()),
        ]);
    }
}
