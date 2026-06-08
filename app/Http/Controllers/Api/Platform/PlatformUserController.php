<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformUserResource;
use App\Models\User;
use App\Services\Platform\PlatformUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformUserController extends Controller
{
    public function __construct(
        protected PlatformUserService $userService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->userService->paginateTenantUsers([
            'search' => $request->query('search'),
            'is_active' => $request->query('is_active'),
            'business_id' => $request->query('business_id'),
        ], (int) $request->query('per_page', 15));

        return PlatformUserResource::collection($paginator)->response();
    }

    public function platformTeam(Request $request): JsonResponse
    {
        $paginator = $this->userService->paginatePlatformTeam((int) $request->query('per_page', 15));

        return PlatformUserResource::collection($paginator)->response();
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $channels = implode(',', config('platform.notification_channels', ['email', 'in_app', 'both']));

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'channel' => ['sometimes', 'in:'.$channels],
        ]);

        $target = User::findOrFail($id);
        $updated = $this->userService->updateStatus(
            $request->user(),
            $target,
            (bool) $validated['is_active'],
            $validated['reason'] ?? null,
            $validated['channel'] ?? config('platform.default_notification_channel', 'both'),
        );

        return response()->json([
            'data' => new PlatformUserResource($updated),
            'message' => $validated['is_active'] ? 'User reactivated.' : 'User deactivated.',
        ]);
    }

    public function assignRole(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', 'exists:platform_roles,name'],
        ]);

        $target = User::findOrFail($id);
        $updated = $this->userService->assignPlatformRole($request->user(), $target, $validated['role']);

        return response()->json([
            'data' => new PlatformUserResource($updated),
            'message' => 'Platform role assigned.',
        ]);
    }

    public function revokeRole(Request $request, int $id, string $role): JsonResponse
    {
        $target = User::findOrFail($id);
        $updated = $this->userService->revokePlatformRole($request->user(), $target, $role);

        return response()->json([
            'data' => new PlatformUserResource($updated),
            'message' => 'Platform role revoked.',
        ]);
    }
}
