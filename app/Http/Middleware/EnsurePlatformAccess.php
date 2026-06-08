<?php

namespace App\Http\Middleware;

use App\Services\Platform\PlatformAdminService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAccess
{
    public function __construct(
        protected PlatformAdminService $platformAdminService,
    ) {}

    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = $request->user();

        if (! $user || ! $this->platformAdminService->isPlatformAdmin($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($permission && ! $user->hasPermissionTo($permission)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
