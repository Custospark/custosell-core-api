<?php

namespace App\Http\Middleware;

use App\Services\ModuleAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEstimatesWorkspaceAccess
{
    public function __construct(
        protected ModuleAccessService $moduleAccess,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->moduleAccess->hasFullEstimatesWorkspace($user)) {
            return response()->json([
                'message' => 'Only the business owner or staff with full Projects & Estimates access can use this resource.',
            ], 403);
        }

        return $next($request);
    }
}
