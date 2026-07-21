<?php

namespace App\Http\Middleware;

use App\Services\Platform\PlatformAdminService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    public function __construct(
        protected PlatformAdminService $platformAdminService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($this->platformAdminService->isPlatformAdmin($user)) {
            return $next($request);
        }

        if ($user->business_id === null) {
            return $next($request);
        }

        $subscription = $user->business->subscription;

        if (! $subscription) {
            return response()->json([
                'message' => 'No active subscription. Please subscribe to continue using Custosell.',
            ], 403);
        }

        if (! $subscription->hasAccess()) {
            return response()->json([
                'message' => match ($subscription->status->value) {
                    'suspended' => 'Your subscription has been suspended due to non-payment. Please make a payment to regain access.',
                    'cancelled' => 'Your subscription has been cancelled. Please contact support to reactivate your account.',
                    'expired' => 'Your trial has expired. Please subscribe to continue using Custosell.',
                    default => 'Your subscription is not active. Please check your billing status.',
                },
            ], 403);
        }

        return $next($request);
    }
}
