<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(10, (int) $request->query('per_page', 20)));

        $paginator = $this->notifications->paginateForUser(
            $request->user(),
            ['unread_only' => filter_var($request->query('unread_only', false), FILTER_VALIDATE_BOOLEAN)],
            $perPage,
        );

        $paginator->getCollection()->transform(fn ($n) => $this->transform($n));

        return response()->json($paginator);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'unread_count' => $this->notifications->unreadCountForUser($request->user()),
            ],
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = $this->notifications->markRead($request->user(), $id);

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        return response()->json([
            'data' => $this->transform($notification),
            'message' => 'Notification marked as read.',
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->notifications->markAllRead($request->user());

        return response()->json([
            'message' => "{$count} notification(s) marked as read.",
            'updated' => $count,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = $this->notifications->deleteForUser($request->user(), $id);

        if (! $deleted) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        return response()->json(['message' => 'Notification deleted.']);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $deleted = $this->notifications->bulkDeleteForUser($request->user(), $data['ids']);

        return response()->json([
            'message' => "{$deleted} notification(s) deleted.",
            'deleted' => $deleted,
        ]);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $count = $this->notifications->deleteAllForUser($request->user());

        return response()->json([
            'message' => "{$count} notification(s) deleted.",
            'deleted' => $count,
        ]);
    }

    /** @return array<string, mixed> */
    private function transform($notification): array
    {
        return [
            'id' => $notification->id,
            'title' => $notification->title,
            'message' => $notification->message,
            'type' => $notification->type,
            'intention' => $notification->intention,
            'channel' => $notification->channel,
            'metadata' => $notification->metadata,
            'business_id' => $notification->business_id,
            'is_read' => $notification->read_at !== null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'sent_at' => $notification->sent_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
