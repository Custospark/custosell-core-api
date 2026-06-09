<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformNotificationDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformNotificationDispatchController extends Controller
{
    public function __construct(
        protected PlatformNotificationDispatchService $dispatches,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(10, (int) $request->query('per_page', 20)));

        $paginator = $this->dispatches->paginate([
            'target_kind' => $request->query('target_kind'),
            'dispatch_type' => $request->query('dispatch_type'),
            'intention' => $request->query('intention'),
            'q' => $request->query('q'),
        ], $perPage);

        return response()->json($paginator);
    }

    public function show(int $id): JsonResponse
    {
        $row = $this->dispatches->find($id);

        if (! $row) {
            return response()->json(['message' => 'Dispatch not found.'], 404);
        }

        return response()->json(['data' => $row]);
    }

    public function destroy(int $id): JsonResponse
    {
        if (! $this->dispatches->delete($id)) {
            return response()->json(['message' => 'Dispatch not found.'], 404);
        }

        return response()->json(['message' => 'Sent message removed from log.']);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:platform_notification_dispatches,id'],
        ]);

        $deleted = $this->dispatches->bulkDelete($data['ids']);

        return response()->json([
            'message' => "{$deleted} sent message(s) removed from log.",
            'deleted' => $deleted,
        ]);
    }
}
