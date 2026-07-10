<?php

namespace App\Http\Middleware;

use App\Services\ModuleAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHrFullAccess
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

        // Match estimates_full: owners need `hr_full` in stored modules for admin HR APIs.
        if ($this->moduleAccess->hasFullHrWorkspace($user)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Only users with full HR & Payroll access can use this resource.',
        ], 403);
    }
}
