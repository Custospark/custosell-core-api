<?php

namespace App\Http\Middleware;

use App\Services\ModuleAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessOwner
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

        if (! $this->moduleAccess->isBusinessOwner($user)) {
            return response()->json([
                'message' => 'Only the business owner can access this resource.',
            ], 403);
        }

        return $next($request);
    }
}
