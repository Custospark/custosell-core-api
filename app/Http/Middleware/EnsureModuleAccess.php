<?php

namespace App\Http\Middleware;

use App\Services\ModuleAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleAccess
{
    public function __construct(
        protected ModuleAccessService $moduleAccess,
    ) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($this->moduleAccess->canAccess($user, $module)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'You do not have access to this module.',
            'module' => $module,
        ], 403);
    }
}
