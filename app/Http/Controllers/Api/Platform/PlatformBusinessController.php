<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Services\Platform\PlatformBusinessService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformBusinessController extends Controller
{
    public function __construct(
        protected PlatformBusinessService $businessService,
    ) {}

    public function stats(Request $request): JsonResponse
    {
        $rangeFrom = $request->query('date_from')
            ? Carbon::parse($request->query('date_from'))->startOfDay()
            : null;
        $rangeTo = $request->query('date_to')
            ? Carbon::parse($request->query('date_to'))->endOfDay()
            : null;

        return response()->json([
            'data' => $this->businessService->onboardingDashboard($rangeFrom, $rangeTo),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(500, max(15, (int) $request->query('per_page', 50)));

        $paginator = $this->businessService->paginate([
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'currency' => $request->query('currency'),
            'activity_status' => $request->query('activity_status'),
            'sort' => $request->query('sort', 'gross_sales_30d'),
            'direction' => $request->query('direction', 'desc'),
        ], $perPage);

        return response()->json($paginator);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $allowed = implode(',', $this->businessService->allowedStatuses());

        $channels = implode(',', config('platform.notification_channels', ['email', 'in_app', 'both']));

        $validated = $request->validate([
            'status' => ['required', 'in:'.$allowed],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'channel' => ['sometimes', 'in:'.$channels],
        ]);

        $business = Business::with('owner')->findOrFail($id);
        $updated = $this->businessService->updateStatus(
            $request->user(),
            $business,
            $validated['status'],
            $validated['reason'],
            $validated['channel'] ?? config('platform.default_notification_channel', 'both'),
        );

        return response()->json([
            'data' => $this->businessService->transformBusiness($updated),
            'message' => 'Business status updated.',
        ]);
    }

    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $allowed = implode(',', $this->businessService->allowedStatuses());

        $channels = implode(',', config('platform.notification_channels', ['email', 'in_app', 'both']));

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:businesses,id'],
            'status' => ['required', 'in:'.$allowed],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'channel' => ['sometimes', 'in:'.$channels],
        ]);

        $count = $this->businessService->bulkUpdateStatus(
            $request->user(),
            $validated['ids'],
            $validated['status'],
            $validated['reason'],
            $validated['channel'] ?? config('platform.default_notification_channel', 'both'),
        );

        return response()->json([
            'message' => "{$count} business(es) updated.",
            'updated' => $count,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $business = Business::findOrFail($id);
        $this->businessService->delete($request->user(), $business, $validated['reason']);

        return response()->json(['message' => 'Business deleted.']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:businesses,id'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $count = $this->businessService->bulkDelete(
            $request->user(),
            $validated['ids'],
            $validated['reason'],
        );

        return response()->json([
            'message' => "{$count} business(es) deleted.",
            'deleted' => $count,
        ]);
    }

    public function resetByEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $owner = User::where('email', $validated['email'])->first();

        if (!$owner) {
            return response()->json(['message' => 'No user found with that email.'], 404);
        }

        $business = Business::where('owner_id', $owner->id)->first();

        if (!$business) {
            return response()->json(['message' => 'That user does not own a business.'], 404);
        }

        $counts = $this->businessService->resetBusinessData($request->user(), $business);

        return response()->json([
            'message' => "Business \"{$business->name}\" (ID: {$business->id}) has been reset.",
            'business_id' => $business->id,
            'business_name' => $business->name,
            'owner_email' => $owner->email,
            'reset_counts' => $counts,
        ]);
    }

    public function resetData(Request $request, int $id): JsonResponse
    {
        $business = Business::findOrFail($id);
        $counts = $this->businessService->resetBusinessData($request->user(), $business);

        return response()->json([
            'message' => "Business \"{$business->name}\" (ID: {$business->id}) has been reset. Estimates, CRM (pipeline), and documents were preserved.",
            'business_id' => $business->id,
            'business_name' => $business->name,
            'reset_counts' => $counts,
        ]);
    }

    public function notify(Request $request): JsonResponse
    {
        $intentions = implode(',', $this->businessService->notificationIntentions());

        $channels = implode(',', config('platform.notification_channels', ['email', 'in_app', 'both']));

        $validated = $request->validate([
            'business_ids' => ['required', 'array', 'min:1'],
            'business_ids.*' => ['integer', 'exists:businesses,id'],
            'intention' => ['required', 'in:'.$intentions],
            'message' => ['required', 'string', 'min:3', 'max:5000'],
            'subject' => ['nullable', 'string', 'max:200'],
            'mark_as_notified' => ['sometimes', 'boolean'],
            'channel' => ['sometimes', 'in:'.$channels],
        ]);

        $sent = $this->businessService->notify(
            $request->user(),
            $validated['business_ids'],
            $validated['intention'],
            $validated['message'],
            $validated['subject'] ?? null,
            (bool) ($validated['mark_as_notified'] ?? false),
            $validated['channel'] ?? config('platform.default_notification_channel', 'both'),
        );

        return response()->json([
            'message' => "Notification sent to {$sent} business(es).",
            'sent' => $sent,
        ]);
    }
}
