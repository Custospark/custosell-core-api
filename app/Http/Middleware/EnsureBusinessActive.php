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

        if ($user->business_id === null) {
            return $next($request);
        }

        $business = $user->relationLoaded('business') ? $user->business : $user->business()->select('id', 'status')->first();
        $blocked = config('platform.blocked_business_statuses', ['restricted', 'suspended']);
        if ($business && in_array($business->status, $blocked, true)) {
            $message = $business->status === 'suspended'
                ? 'Your business account has been suspended.'
                : 'Your business account has been restricted.';

            return response()->json(['message' => $message], 403);
        }

        return $next($request);
    }
}
