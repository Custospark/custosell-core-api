<?php

namespace App\Http\Middleware;

use App\Services\Platform\PlatformAdminService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Processes requests based on the account's platform status.
 *
 * - Platform admins always pass (they manage all accounts).
 * - Deactivated users are blocked outright.
 * - Restricted / suspended businesses are blocked.
 * - Warning / notified accounts pass but the resolved status is echoed back so
 *   the frontend can adapt UX (banners, read-only hints) to the current status.
 */
class EnsureAccountUsable
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
            return $this->withStatusHeader($next($request), 'active');
        }

        $business = $user->relationLoaded('business') ? $user->business : $user->business()->select('id', 'status')->first();
        $blocked = config('platform.blocked_business_statuses', ['restricted', 'suspended']);

        if ($business && in_array($business->status, $blocked, true)) {
            $message = $business->status === 'suspended'
                ? 'Your business account has been suspended.'
                : 'Your business account has been restricted.';

            return response()->json(['message' => $message], 403);
        }

        return $this->withStatusHeader($next($request), $business?->status ?? 'active');
    }

    private function withStatusHeader(Response $response, string $status): Response
    {
        if ($response->headers->has('X-Account-Status')) {
            return $response;
        }

        return $response->header('X-Account-Status', $status);
    }
}
