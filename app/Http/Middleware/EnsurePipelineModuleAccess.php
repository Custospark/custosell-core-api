<?php

namespace App\Http\Middleware;

use App\Services\ProjectAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePipelineModuleAccess
{
    public function __construct(
        protected ProjectAccessService $projectAccess,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($this->projectAccess->canAccessPipelineRoute($user, $request)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'You do not have access to this pipeline resource.',
            'module' => 'pipeline',
        ], 403);
    }
}
