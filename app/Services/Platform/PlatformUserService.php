<?php

namespace App\Services\Platform;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PlatformUserService
{
    public function __construct(
        protected PlatformUserQueryBuilder $queries,
        protected PlatformAdminService $adminService,
        protected PlatformNotificationService $notifications,
        protected PlatformAuditService $audit,
        protected PlatformNotificationDispatchService $dispatches,
        protected PlatformSubscriptionPrivilegeService $subscriptionPrivileges,
    ) {}

    public function paginateTenantUsers(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->queries->paginateTenantUsers($filters, $perPage);
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
     * @param  array{account_type?: string, email?: string, password?: string, plan_id?: int, billing_cycle?: string, subscription_status?: string, onboarding_fee_paid?: bool, next_billing_date?: string, trial_ends_at?: string, grace_period_ends_at?: string, suspended_at?: string, ends_at?: string}  $changes
     */
    public function updatePrivileges(User $actor, User $target, array $changes): User
    {
        $target = $this->applyUserFields($actor, $target, $changes);

        if (isset($changes['plan_id']) || isset($changes['billing_cycle'])
            || isset($changes['subscription_status']) || isset($changes['onboarding_fee_paid'])
            || isset($changes['next_billing_date']) || isset($changes['trial_ends_at'])
            || isset($changes['grace_period_ends_at']) || isset($changes['suspended_at'])
            || isset($changes['ends_at'])) {
            $this->subscriptionPrivileges->apply($actor, $target, $changes);
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
        $diff = [];

        if (isset($changes['account_type']) && $changes['account_type'] !== $target->account_type) {
            $diff['account_type'] = ['from' => $target->account_type, 'to' => $changes['account_type']];
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

            $diff['email'] = ['from' => $target->email, 'to' => $normalized];
            $target->update(['email' => $normalized]);
        }

        if (isset($changes['password']) && $changes['password'] !== '') {
            $target->update(['password' => Hash::make($changes['password'])]);
        }

        $this->audit->log($actor, 'user.privileges.fields', 'user', $target->id, null, [
            'diff' => $diff,
            'password_changed' => isset($changes['password']) && $changes['password'] !== '',
        ]);

        return $target;
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

        $target->update([
            'is_active' => $isActive,
            'status' => $isActive ? 'active' : 'deactivated',
            'status_changed_at' => now(),
        ]);

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
