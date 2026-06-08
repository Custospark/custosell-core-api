<?php

namespace App\Http\Middleware;

use App\Services\Platform\PlatformAdminService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessActive
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

        if (! $user->is_active) {
            return response()->json(['message' => 'Your account has been deactivated.'], 403);
        }

        $business = $user->business;
        if ($business && $business->status === 'suspended') {
            return response()->json(['message' => 'Your business account has been suspended.'], 403);
        }

        return $next($request);
    }
}
