<?php

namespace App\Services\Platform;

use App\Models\Subscription;
use App\Models\User;
use App\Services\Contracts\SubscriptionServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PlatformUserService
{
    public function __construct(
        protected PlatformAdminService $adminService,
        protected PlatformNotificationService $notifications,
        protected PlatformAuditService $audit,
        protected PlatformNotificationDispatchService $dispatches,
        protected SubscriptionServiceInterface $subscriptionService,
    ) {}

    public function paginateTenantUsers(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = User::query()
            ->with(['business:id,name,owner_id,status', 'business.subscription.plan:id,name,slug', 'role:id,name,slug', 'roles:id,name'])
            ->whereNotNull('business_id');

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                    ->orWhere('email', 'like', $search)
                    ->orWhere('phone', 'like', $search)
                    ->orWhereHas('business', fn ($b) => $b->where('name', 'like', $search));
            });
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['account_type'])) {
            $query->where('account_type', $filters['account_type']);
        }

        if (! empty($filters['business_id'])) {
            $query->where('business_id', (int) $filters['business_id']);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function delete(User $actor, User $target, string $reason): void
    {
        $this->assertCanDelete($actor, $target);

        $this->audit->log($actor, 'user.deleted', 'user', $target->id, $reason, [
            'email' => $target->email,
            'name' => $target->name,
        ]);

        $target->forceDelete();
    }

    /**
     * @return array{deleted: int, skipped: int, errors: list<array{email: string, message: string}>}
     */
    public function bulkDelete(User $actor, array $ids, string $reason): array
    {
        $deleted = 0;
        $skipped = 0;
        $errors = [];

        $users = User::whereIn('id', $ids)->get();

        foreach ($users as $target) {
            try {
                $this->delete($actor, $target, $reason);
                $deleted++;
            } catch (ValidationException $e) {
                $skipped++;
                $errors[] = [
                    'email' => $target->email,
                    'message' => collect($e->errors())->flatten()->first() ?? 'Could not delete user.',
                ];
            }
        }

        return compact('deleted', 'skipped', 'errors');
    }

    /**
     * @param  list<string>|null  $emails
     * @param  list<int>|null  $ids
     * @return array{processed: int, not_found: list<string>, errors: list<array{email: string, message: string}>}
     */
    public function bulkPlatformRoles(
        User $actor,
        string $roleName,
        string $action,
        ?array $emails = null,
        ?array $ids = null,
    ): array {
        [$users, $notFound] = $this->resolveUsersByEmailOrIds($emails, $ids);

        $processed = 0;
        $errors = [];

        foreach ($users as $target) {
            try {
                if ($action === 'revoke') {
                    $this->revokePlatformRole($actor, $target, $roleName);
                } else {
                    $this->assignPlatformRole($actor, $target, $roleName);
                }
                $processed++;
            } catch (ValidationException $e) {
                $errors[] = [
                    'email' => $target->email,
                    'message' => collect($e->errors())->flatten()->first() ?? 'Could not update role.',
                ];
            }
        }

        return [
            'processed' => $processed,
            'not_found' => $notFound,
            'errors' => $errors,
        ];
    }

    /**
     * Update access privileges for one user: account type, email, password, and
     * the linked business subscription (plan, status, onboarding fee, next billing).
     *
     * Each field is optional; only provided fields are changed. Platform admins use
     * this as the last line of defense for wrong-emails and lost passwords.
     *
     * @param  array{account_type?: string, email?: string, password?: string, plan_id?: int, billing_cycle?: string, subscription_status?: string, onboarding_fee_paid?: bool, next_billing_date?: string}  $changes
     */
    public function updatePrivileges(User $actor, User $target, array $changes): User
    {
        $target = $this->applyUserFields($actor, $target, $changes);

        if (isset($changes['plan_id']) || isset($changes['billing_cycle'])
            || isset($changes['subscription_status']) || isset($changes['onboarding_fee_paid'])
            || isset($changes['next_billing_date'])) {
            $this->applySubscriptionChanges($actor, $target, $changes);
        }

        return $target->fresh(['business.subscription.plan', 'role', 'roles']);
    }

    /**
     * Apply a privilege change to many users (bulk action).
     *
     * @return array{processed: int, errors: list<array{email: string, message: string}>}
     */
    public function bulkUpdatePrivileges(User $actor, array $ids, array $changes): array
    {
        $processed = 0;
        $errors = [];

        $users = User::whereIn('id', $ids)->get();

        foreach ($users as $target) {
            try {
                $this->updatePrivileges($actor, $target, $changes);
                $processed++;
            } catch (ValidationException $e) {
                $errors[] = [
                    'email' => $target->email,
                    'message' => collect($e->errors())->flatten()->first() ?? 'Could not update privileges.',
                ];
            }
        }

        return compact('processed', 'errors');
    }

    /** @param  array{account_type?: string, email?: string, password?: string}  $changes */
    private function applyUserFields(User $actor, User $target, array $changes): User
    {
        if (isset($changes['account_type'])) {
            $target->update(['account_type' => $changes['account_type']]);
        }

        if (isset($changes['email']) && $changes['email'] !== $target->email) {
            $normalized = strtolower(trim($changes['email']));
            $exists = User::whereRaw('LOWER(email) = ?', [$normalized])
                ->where('id', '!=', $target->id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages(['email' => 'That email is already in use.']);
            }

            $target->update(['email' => $normalized]);
        }

        if (isset($changes['password']) && $changes['password'] !== '') {
            $target->update(['password' => Hash::make($changes['password'])]);
        }

        $this->audit->log($actor, 'user.privileges.fields', 'user', $target->id, null, [
            'account_type' => $changes['account_type'] ?? null,
            'email' => $changes['email'] ?? null,
            'password_changed' => isset($changes['password']) && $changes['password'] !== '',
        ]);

        return $target;
    }

    /**
     * @param  array{plan_id?: int, billing_cycle?: string, subscription_status?: string, onboarding_fee_paid?: bool, next_billing_date?: string}  $changes
     */
    private function applySubscriptionChanges(User $actor, User $target, array $changes): void
    {
        $business = $target->business ?? $target->ownedBusiness()->first();

        if (! $business) {
            throw ValidationException::withMessages(['business' => 'This account has no linked business.']);
        }

        $subscription = $business->subscription ?? $business->subscription()->first();

        if ($subscription === null && isset($changes['plan_id'])) {
            $subscription = $this->buildSubscription($business, $changes);
        }

        if ($subscription === null) {
            throw ValidationException::withMessages(['subscription' => 'No subscription exists. Select a plan to create one.']);
        }

        $updates = [];

        if (isset($changes['plan_id']) && (int) $changes['plan_id'] !== $subscription->plan_id) {
            $plan = \App\Models\Plan::find((int) $changes['plan_id']);
            if (! $plan) {
                throw ValidationException::withMessages(['plan_id' => 'Selected plan not found.']);
            }

            $updates['plan_id'] = $plan->id;
            $updates['price_monthly_usd'] = $plan->price_monthly_usd;
            $updates['price_yearly_usd'] = $plan->price_yearly_usd;
            $updates['onboarding_fee_usd'] = $plan->onboarding_fee_usd;
        }

        if (isset($changes['billing_cycle'])) {
            $updates['billing_cycle'] = $changes['billing_cycle'];
            $this->resetNextBilling($updates);
        }

        if (isset($changes['subscription_status'])) {
            $updates['status'] = $changes['subscription_status'];
        }

        if (isset($changes['onboarding_fee_paid'])) {
            $updates['onboarding_fee_paid'] = (bool) $changes['onboarding_fee_paid'];
        }

        if (isset($changes['next_billing_date']) && $changes['next_billing_date'] !== '') {
            $updates['next_billing_date'] = \Illuminate\Support\Carbon::parse($changes['next_billing_date']);
        }

        if ($updates !== []) {
            $subscription->update($updates);
        }

        $this->audit->log($actor, 'user.privileges.subscription', 'subscription', $subscription->id, null, [
            'changes' => array_keys($updates),
        ]);
    }

    /** @param  array{plan_id?: int, billing_cycle?: string}  $changes */
    private function buildSubscription(\App\Models\Business $business, array $changes): Subscription
    {
        $planId = (int) ($changes['plan_id'] ?? null);
        $billingCycle = $changes['billing_cycle'] ?? 'monthly';

        $subscription = $this->subscriptionService->subscribe($business->id, $planId, $billingCycle);

        return $this->subscriptionService->activateAfterOnboarding($subscription);
    }

    private function resetNextBilling(array &$updates): void
    {
        $cycle = $updates['billing_cycle'] ?? 'monthly';
        $updates['next_billing_date'] = $cycle === 'yearly'
            ? now()->addYear()
            : now()->addMonth();
    }

    /**
     * @param  list<string>|null  $emails
     * @param  list<int>|null  $ids
     * @return array{0: \Illuminate\Support\Collection<int, User>, 1: list<string>}
     */
    protected function resolveUsersByEmailOrIds(?array $emails, ?array $ids): array
    {
        $users = collect();
        $notFound = [];

        if (! empty($ids)) {
            $users = User::whereIn('id', $ids)->get()->keyBy('id');
        }

        if (! empty($emails)) {
            foreach ($emails as $email) {
                $normalized = strtolower(trim($email));
                if ($normalized === '') {
                    continue;
                }

                $user = User::whereRaw('LOWER(email) = ?', [$normalized])->first();
                if (! $user) {
                    $notFound[] = $email;
                    continue;
                }

                $users->put($user->id, $user);
            }
        }

        return [$users->values(), array_values(array_unique($notFound))];
    }

    protected function assertCanDelete(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            throw ValidationException::withMessages(['user' => 'You cannot delete your own account.']);
        }

        if ($target->ownedBusiness()->exists()) {
            throw ValidationException::withMessages(['user' => 'Cannot delete a business owner. Transfer ownership first.']);
        }

        if ($target->hasRole('platform-admin')) {
            $remaining = $this->countUsersWithPlatformRole('platform-admin', $target->id);
            if ($remaining < 1) {
                throw ValidationException::withMessages(['user' => 'Cannot delete the last platform admin.']);
            }
        }
    }

    protected function countUsersWithPlatformRole(string $roleName, ?int $exceptUserId = null): int
    {
        $query = User::whereHas('roles', fn ($q) => $q->where('name', $roleName));

        if ($exceptUserId !== null) {
            $query->where('id', '!=', $exceptUserId);
        }

        return $query->count();
    }

    public function updateStatus(
        User $actor,
        User $target,
        bool $isActive,
        ?string $reason,
        string $channel = 'both',
    ): User
    {
        if ($actor->id === $target->id) {
            throw ValidationException::withMessages(['is_active' => 'You cannot change your own account status.']);
        }

        if (! $isActive && $target->hasRole('platform-admin')) {
            $remaining = $this->countUsersWithPlatformRole('platform-admin', $target->id);
            if ($remaining < 1) {
                throw ValidationException::withMessages(['is_active' => 'Cannot deactivate the last platform admin.']);
            }
        }

        $wasActive = (bool) $target->is_active;

        $target->update(['is_active' => $isActive]);

        $this->audit->log(
            $actor,
            $isActive ? 'user.reactivated' : 'user.deactivated',
            'user',
            $target->id,
            $reason,
        );

        $this->notifications->notifyUserStatusChange($target, $isActive, $reason, $channel);

        $this->dispatches->recordStatusChange(
            $actor,
            'user',
            $reason ?? '',
            $channel,
            $wasActive ? 'active' : 'inactive',
            $isActive ? 'active' : 'inactive',
            [$this->dispatches->recipientFromUser($target->loadMissing('business'))],
            $isActive ? 'account_notice' : 'warning_notice',
        );

        return $target->fresh(['business', 'role', 'roles']);
    }

    /** @return list<string> */
    public function notificationIntentions(): array
    {
        return config('platform.user_notification_intentions', [
            'announcement',
            'warning_notice',
            'policy_update',
            'reactivation_nudge',
            'account_notice',
            'custom',
        ]);
    }

    public function notify(
        User $actor,
        array $userIds,
        string $intention,
        string $message,
        ?string $subject = null,
        bool $markAsNotified = false,
        string $channel = 'both',
    ): int {
        $users = User::query()
            ->with('business:id,name')
            ->whereIn('id', $userIds)
            ->whereNull('deleted_at')
            ->get();

        $sent = 0;

        foreach ($users as $target) {
            $this->notifications->notifyUserMessage($target, $intention, $message, $subject, $channel);
            $this->audit->log($actor, 'user.notified', 'user', $target->id, null, [
                'intention' => $intention,
                'subject' => $subject,
                'channel' => $channel,
                'mark_as_notified' => $markAsNotified,
            ]);

            if ($markAsNotified) {
                $this->audit->log($actor, 'user.marked_notified', 'user', $target->id, null, [
                    'intention' => $intention,
                ]);
            }

            $sent++;
        }

        if ($users->isNotEmpty()) {
            $this->dispatches->recordMessage(
                $actor,
                'user',
                $intention,
                $message,
                $channel,
                $users->map(fn (User $user) => $this->dispatches->recipientFromUser($user))->all(),
                $subject,
                $markAsNotified,
            );
        }

        return $sent;
    }

    public function assignPlatformRole(User $actor, User $target, string $roleName): User
    {
        if (! $target->hasRole($roleName)) {
            $target->assignRole($roleName);
            $this->audit->log($actor, 'platform_role.assigned', 'user', $target->id, null, ['role' => $roleName]);
        }

        return $target->fresh(['roles']);
    }

    public function revokePlatformRole(User $actor, User $target, string $roleName): User
    {
        if ($roleName === 'platform-admin' && $target->hasRole('platform-admin')) {
            $remaining = $this->countUsersWithPlatformRole('platform-admin', $target->id);
            if ($remaining < 1) {
                throw ValidationException::withMessages(['role' => 'Cannot remove the last platform admin role.']);
            }
        }

        $target->removeRole($roleName);
        $this->audit->log($actor, 'platform_role.revoked', 'user', $target->id, null, ['role' => $roleName]);

        return $target->fresh(['roles']);
    }
}
