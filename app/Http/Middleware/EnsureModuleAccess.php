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

    public function handle(Request $request, Closure $next, string $modules): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        foreach (explode(',', $modules) as $module) {
            if ($this->moduleAccess->canAccess($user, trim($module))) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'You do not have access to this module.',
            'module' => $modules,
        ], 403);
    }
}
