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

    public function destroy(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $target = User::findOrFail($id);
        $this->userService->delete($request->user(), $target, $validated['reason']);

        return response()->json(['message' => 'User deleted.']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $result = $this->userService->bulkDelete(
            $request->user(),
            $validated['ids'],
            $validated['reason'],
        );

        $message = "{$result['deleted']} user(s) deleted.";
        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} skipped.";
        }

        return response()->json([
            'message' => $message,
            'deleted' => $result['deleted'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
        ]);
    }

    public function bulkAssignRoles(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'emails' => ['sometimes', 'array'],
            'emails.*' => ['email'],
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['integer', 'exists:users,id'],
            'role' => ['required', 'string', 'exists:platform_roles,name'],
            'action' => ['sometimes', 'in:assign,revoke'],
        ]);

        if (empty($validated['emails'] ?? []) && empty($validated['ids'] ?? [])) {
            return response()->json(['message' => 'Provide at least one user email or id.'], 422);
        }

        $result = $this->userService->bulkPlatformRoles(
            $request->user(),
            $validated['role'],
            $validated['action'] ?? 'assign',
            $validated['emails'] ?? null,
            $validated['ids'] ?? null,
        );

        $actionLabel = ($validated['action'] ?? 'assign') === 'revoke' ? 'revoked from' : 'assigned to';
        $message = "Role {$actionLabel} {$result['processed']} user(s).";

        if (count($result['not_found']) > 0) {
            $message .= ' '.count($result['not_found']).' email(s) not found.';
        }

        if (count($result['errors']) > 0) {
            $message .= ' '.count($result['errors']).' failed.';
        }

        return response()->json([
            'message' => $message,
            'processed' => $result['processed'],
            'not_found' => $result['not_found'],
            'errors' => $result['errors'],
        ]);
    }

    public function notify(Request $request): JsonResponse
    {
        $intentions = implode(',', $this->userService->notificationIntentions());
        $channels = implode(',', config('platform.notification_channels', ['email', 'in_app', 'both']));

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'intention' => ['required', 'in:'.$intentions],
            'message' => ['required', 'string', 'min:3', 'max:5000'],
            'subject' => ['nullable', 'string', 'max:200'],
            'mark_as_notified' => ['sometimes', 'boolean'],
            'channel' => ['sometimes', 'in:'.$channels],
        ]);

        $sent = $this->userService->notify(
            $request->user(),
            $validated['user_ids'],
            $validated['intention'],
            $validated['message'],
            $validated['subject'] ?? null,
            (bool) ($validated['mark_as_notified'] ?? false),
            $validated['channel'] ?? config('platform.default_notification_channel', 'both'),
        );

        return response()->json([
            'message' => "Notification sent to {$sent} user(s).",
            'sent' => $sent,
        ]);
    }
}
