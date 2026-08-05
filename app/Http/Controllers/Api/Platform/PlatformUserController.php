<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformUserResource;
use App\Models\Plan;
use App\Models\User;
use App\Services\Platform\PlatformUserMetricsService;
use App\Services\Platform\PlatformUserService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PlatformUserController extends Controller
{
    public function __construct(
        protected PlatformUserService $userService,
        protected PlatformUserMetricsService $metrics,
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
            'data' => $this->metrics->onboardingDashboard($rangeFrom, $rangeTo),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(500, max(15, (int) $request->query('per_page', 15)));

        $paginator = $this->userService->paginateTenantUsers([
            'search' => $request->query('search'),
            'is_active' => $request->query('is_active'),
            'account_type' => $request->query('account_type'),
            'business_id' => $request->query('business_id'),
            'status' => $request->query('status'),
            'status_duration_days' => $request->query('status_duration_days'),
            'login_activity' => $request->query('login_activity'),
            'business' => $request->query('business'),
        ], $perPage);

        $paginator->getCollection()->transform(fn (User $user) => new PlatformUserResource($user));

        return response()->json($paginator);
    }

    public function updatePrivileges(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'account_type' => ['sometimes', 'in:business,personal,storefront_buyer'],
            'email' => ['sometimes', 'email', 'max:255'],
            'password' => ['sometimes', 'string', 'min:8', 'max:255'],
            'plan_id' => ['sometimes', 'integer', 'exists:plans,id'],
            'billing_cycle' => ['sometimes', 'in:monthly,yearly'],
            'subscription_status' => ['sometimes', 'in:trial,active,past_due,suspended,cancelled,expired'],
            'onboarding_fee_paid' => ['sometimes', 'boolean'],
            'next_billing_date' => ['sometimes', 'date'],
            'trial_ends_at' => ['sometimes', 'date'],
            'grace_period_ends_at' => ['sometimes', 'date'],
            'suspended_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date'],
        ]);

        $target = User::findOrFail($id);
        $this->validateAccountPlanPairing($validated);
        $updated = $this->userService->updatePrivileges($request->user(), $target, $validated);

        return response()->json([
            'data' => new PlatformUserResource($updated),
            'message' => 'Account privileges updated.',
        ]);
    }

    public function bulkUpdatePrivileges(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:users,id'],
            'account_type' => ['sometimes', 'in:business,personal,storefront_buyer'],
            'plan_id' => ['sometimes', 'integer', 'exists:plans,id'],
            'billing_cycle' => ['sometimes', 'in:monthly,yearly'],
            'subscription_status' => ['sometimes', 'in:trial,active,past_due,suspended,cancelled,expired'],
            'onboarding_fee_paid' => ['sometimes', 'boolean'],
            'next_billing_date' => ['sometimes', 'date'],
            'trial_ends_at' => ['sometimes', 'date'],
            'grace_period_ends_at' => ['sometimes', 'date'],
            'suspended_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date'],
        ]);

        $changes = array_diff_key($validated, ['ids' => true]);

        $this->validateAccountPlanPairing($changes);

        $result = $this->userService->bulkUpdatePrivileges(
            $request->user(),
            $validated['ids'],
            $changes,
        );

        $message = "Privileges updated for {$result['processed']} user(s).";
        if (count($result['errors']) > 0) {
            $message .= ' '.count($result['errors']).' failed.';
        }

        $variant = count($result['errors']) > 0 ? 'warning' : 'success';

        return response()->json([
            'message' => $message,
            'processed' => $result['processed'],
            'errors' => $result['errors'],
            'variant' => $variant,
        ]);
    }

    /**
     * Keep account type and subscription consistent:
     * - storefront buyer accounts have no subscription at all.
     * - when both an account type and a plan are given, the plan must match
     *   the account type (business plans for business, personal for personal).
     *
     * @param  array<string, mixed>  $validated
     */
    private function validateAccountPlanPairing(array $validated): void
    {
        $accountType = $validated['account_type'] ?? null;

        $subscriptionKeys = [
            'plan_id', 'billing_cycle', 'subscription_status', 'onboarding_fee_paid',
            'next_billing_date', 'trial_ends_at', 'grace_period_ends_at',
            'suspended_at', 'ends_at',
        ];

        if ($accountType === 'storefront_buyer') {
            if (count(array_intersect($subscriptionKeys, array_keys($validated))) > 0) {
                throw ValidationException::withMessages([
                    'account_type' => 'Storefront buyer accounts cannot have a subscription.',
                ]);
            }

            return;
        }

        if ($accountType !== null && isset($validated['plan_id'])) {
            $plan = Plan::find($validated['plan_id']);

            if ($plan && $plan->type !== $accountType) {
                throw ValidationException::withMessages([
                    'plan_id' => "Selected plan is for {$plan->type} accounts, not {$accountType}.",
                ]);
            }
        }
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
