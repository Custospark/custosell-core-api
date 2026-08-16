<?php

namespace App\Services;

use App\Models\LinkedAccount;
use App\Models\User;
use App\Services\Billing\SubscriptionStateMachineService;
use App\Services\Contracts\AccountVerificationServiceInterface;
use App\Services\Contracts\UserServiceInterface;
use App\Services\Platform\PlatformAdminService;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Linked accounts let a user switch between several of their own accounts
 * without logging out. The account used to link the others becomes the
 * primary/default; the rest are secondary.
 *
 * Security: linking and unlinking both require a one-time security code sent
 * to the account being linked/unlinked. The code confirms that the account's
 * owner approves the action - credentials alone are not enough.
 *
 * Switching returns the same auth payload as /auth/me so the frontend auth
 * slice can hydrate exactly like a normal login - with the target account's
 * role, modules, locations, subscription and data scope intact.
 */
class LinkedAccountService
{
    public const RELATION_PRIMARY = 'primary';
    public const RELATION_SECONDARY = 'secondary';

    public function __construct(
        protected UserServiceInterface $userService,
        protected PlatformAdminService $platformAdminService,
        protected AccountVerificationServiceInterface $verificationService,
        protected SubscriptionStateMachineService $subscriptionStateMachine,
    ) {}

    /**
     * Validate credentials of the account to link and issue a security code to
     * that account's email. The link is created only after confirmLink().
     *
     * @return array{message: string, target_user_id: int}
     */
    public function initiateLink(int $ownerUserId, string $email, string $password, ?string $ip, ?string $userAgent): array
    {
        $target = $this->userService->login($email, $password);
        if (!$target) {
            throw ValidationException::withMessages([
                'email' => 'The email or password you entered does not match any account.',
            ]);
        }

        if ($target->id === $ownerUserId) {
            throw ValidationException::withMessages([
                'email' => 'This is already your current account.',
            ]);
        }

        // Platform admins must never be linkable as a secondary account from a
        // normal login - switching into them would escalate privileges.
        if ($this->platformAdminService->isPlatformAdmin($target)) {
            throw ValidationException::withMessages([
                'email' => 'This account cannot be linked.',
            ]);
        }

        if ($this->alreadyLinked($ownerUserId, $target->id)) {
            throw ValidationException::withMessages([
                'email' => 'This account is already linked.',
            ]);
        }

        $this->verificationService->issue(
            $target,
            AccountVerificationServiceInterface::PURPOSE_LINK_ACCOUNT,
            $ip,
            $userAgent,
            ['target_user_id' => $target->id],
        );

        return [
            'message' => 'A security code has been sent to the account you are linking.',
            'target_user_id' => $target->id,
        ];
    }

    /**
     * Verify the security code sent to the account being linked and create the
     * link. The logged-in (owner) account is the default; every newly linked
     * account starts as secondary.
     *
     * @return array{relation: string, linked_account: array<string, mixed>}
     */
    public function confirmLink(int $ownerUserId, int $targetUserId, string $code): array
    {
        $target = User::find($targetUserId);
        if (!$target) {
            throw ValidationException::withMessages(['code' => 'That security code is invalid or has expired.']);
        }

        $context = $this->verificationService->verify(
            $target,
            AccountVerificationServiceInterface::PURPOSE_LINK_ACCOUNT,
            $code,
        );

        if (!$context || (int) ($context['target_user_id'] ?? 0) !== $targetUserId) {
            throw ValidationException::withMessages(['code' => 'That security code is invalid or has expired.']);
        }

        $existing = LinkedAccount::query()
            ->where('owner_user_id', $ownerUserId)
            ->where('linked_user_id', $targetUserId)
            ->first();

        if ($existing) {
            return ['relation' => $existing->relation, 'linked_account' => $this->summarize($target, $existing)];
        }

        // Ensure the logged-in account is registered as the default before
        // adding a new secondary link.
        $this->ensureSelfPrimary($ownerUserId);

        $link = DB::transaction(function () use ($ownerUserId, $targetUserId) {
            return LinkedAccount::create([
                'owner_user_id' => $ownerUserId,
                'linked_user_id' => $targetUserId,
                'relation' => self::RELATION_SECONDARY,
            ]);
        });

        Log::info('[LinkedAccounts] account linked', [
            'owner_user_id' => $ownerUserId,
            'linked_user_id' => $targetUserId,
            'relation' => self::RELATION_SECONDARY,
        ]);

        return ['relation' => self::RELATION_SECONDARY, 'linked_account' => $this->summarize($target, $link)];
    }

    /**
     * List every account the owner can switch to. The logged-in account is the
     * default (primary); linked accounts follow, ordered primary first.
     *
     * @return array<string, mixed>
     */
    public function listFor(int $ownerUserId): array
    {
        $this->ensureSelfPrimary($ownerUserId);

        $links = LinkedAccount::query()
            ->where('owner_user_id', $ownerUserId)
            ->with(['linkedUser.business.subscription.plan'])
            ->orderByRaw("CASE WHEN relation = 'primary' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->get();

        $accounts = $links->map(fn (LinkedAccount $link) => $this->summarize($link->linkedUser, $link));

        return [
            'primary' => $accounts->firstWhere('relation', self::RELATION_PRIMARY),
            'accounts' => $accounts->values()->all(),
        ];
    }

    /**
     * Switch to a linked account - returns the full auth payload for that
     * account (same shape as /auth/me) so the client can hydrate its session.
     *
     * Switching applies the same account-status constraints as logging in:
     * the target must be active, its business must not be suspended/restricted
     * (unless it is a platform admin), and the subscription is reconciled so
     * the returned access reflects the true current status. No password or
     * email is required - the owner already proved who they are.
     */
    public function switchTo(int $ownerUserId, int $linkedUserId): array
    {
        if ($ownerUserId === $linkedUserId) {
            throw ValidationException::withMessages([
                'linked_account' => 'You are already signed in to this account.',
            ]);
        }

        $this->linkOrFail($ownerUserId, $linkedUserId);

        $user = User::query()
            ->with(['role', 'roles', 'business.subscription.plan', 'business.subscription.referral.referralCode', 'location', 'locations'])
            ->findOrFail($linkedUserId);

        if (! ($user->is_active ?? true)) {
            throw ValidationException::withMessages([
                'linked_account' => 'This account has been deactivated and cannot be used.',
            ]);
        }

        // Mirror authResponse: reconcile subscription so access reflects the
        // true status, then block suspended/restricted businesses.
        $this->reconcileSubscription($user);

        $isPlatformAdmin = $this->platformAdminService->isPlatformAdmin($user);
        if (! $isPlatformAdmin && $user->business_id) {
            $business = $user->business ?? \App\Models\Business::query()->select('id', 'status')->find($user->business_id);
            $blocked = config('platform.blocked_business_statuses', ['restricted', 'suspended']);
            if ($business && in_array($business->status, $blocked, true)) {
                $message = $business->status === 'suspended'
                    ? 'This account is suspended.'
                    : 'This account is restricted.';
                throw ValidationException::withMessages(['linked_account' => $message]);
            }
        }

        return [
            'user' => (new UserResource($user))->resolve(),
        ];
    }

    /**
     * Mirror AuthController::reconcileSubscription - process due subscription
     * transitions and reload the business so the payload is current.
     */
    protected function reconcileSubscription(User $user): void
    {
        if (!$user->business_id) {
            return;
        }

        $subscription = $user->business?->subscription;
        if (!$subscription) {
            return;
        }

        $this->subscriptionStateMachine->processDueTransitions($subscription);

        $user->setRelation('business', $user->business->fresh()->load([
            'subscription.plan',
            'subscription.referral.referralCode',
        ]));
    }

    /**
     * Promote a linked account to primary; the previous primary demotes.
     */
    public function setPrimary(int $ownerUserId, int $linkedUserId): void
    {
        DB::transaction(function () use ($ownerUserId, $linkedUserId) {
            $target = $this->linkOrFail($ownerUserId, $linkedUserId);

            LinkedAccount::query()
                ->where('owner_user_id', $ownerUserId)
                ->where('relation', self::RELATION_PRIMARY)
                ->update(['relation' => self::RELATION_SECONDARY]);

            $target->update(['relation' => self::RELATION_PRIMARY]);
        });
    }

    /**
     * Issue a security code to the account being unlinked; the unlink only
     * happens after confirmUnlink() verifies it.
     */
    public function initiateUnlink(int $ownerUserId, int $linkedUserId, ?string $ip, ?string $userAgent): array
    {
        $link = $this->linkOrFail($ownerUserId, $linkedUserId);

        if ($link->relation === self::RELATION_PRIMARY) {
            throw ValidationException::withMessages([
                'linked_account' => 'Set another account as your default before removing this one.',
            ]);
        }

        $this->verificationService->issue(
            $link->linkedUser,
            AccountVerificationServiceInterface::PURPOSE_UNLINK_ACCOUNT,
            $ip,
            $userAgent,
            ['linked_user_id' => $linkedUserId],
        );

        return [
            'message' => 'A security code has been sent to the account you are unlinking.',
            'linked_user_id' => $linkedUserId,
        ];
    }

    /**
     * Verify the code sent to the account being unlinked, then remove the link.
     */
    public function confirmUnlink(int $ownerUserId, int $linkedUserId, string $code): void
    {
        $link = $this->linkOrFail($ownerUserId, $linkedUserId);

        if ($link->relation === self::RELATION_PRIMARY) {
            throw ValidationException::withMessages([
                'linked_account' => 'Set another account as your default before removing this one.',
            ]);
        }

        $context = $this->verificationService->verify(
            $link->linkedUser,
            AccountVerificationServiceInterface::PURPOSE_UNLINK_ACCOUNT,
            $code,
        );

        if (!$context || (int) ($context['linked_user_id'] ?? 0) !== $linkedUserId) {
            throw ValidationException::withMessages(['code' => 'That security code is invalid or has expired.']);
        }

        $link->delete();

        Log::info('[LinkedAccounts] account unlinked', [
            'owner_user_id' => $ownerUserId,
            'linked_user_id' => $linkedUserId,
        ]);
    }

    /**
     * Resolve the link row for an owner -> linked pair, enforcing ownership.
     */
    protected function linkOrFail(int $ownerUserId, int $linkedUserId): LinkedAccount
    {
        $link = LinkedAccount::query()
            ->where('owner_user_id', $ownerUserId)
            ->where('linked_user_id', $linkedUserId)
            ->first();

        if (!$link) {
            throw ValidationException::withMessages([
                'linked_account' => 'This account is not linked.',
            ]);
        }

        return $link;
    }

    protected function alreadyLinked(int $ownerUserId, int $linkedUserId): bool
    {
        return LinkedAccount::query()
            ->where('owner_user_id', $ownerUserId)
            ->where('linked_user_id', $linkedUserId)
            ->exists();
    }

    /**
     * Ensure the logged-in account is registered as the default. Represented as
     * a self-link (owner_user_id == linked_user_id, relation=primary) so it
     * appears first in the switch list with the Primary badge.
     */
    protected function ensureSelfPrimary(int $ownerUserId): void
    {
        $hasPrimary = LinkedAccount::query()
            ->where('owner_user_id', $ownerUserId)
            ->where('relation', self::RELATION_PRIMARY)
            ->exists();

        if ($hasPrimary) {
            return;
        }

        LinkedAccount::firstOrCreate(
            ['owner_user_id' => $ownerUserId, 'linked_user_id' => $ownerUserId],
            ['relation' => self::RELATION_PRIMARY],
        );
    }

    /**
     * Small summary of a linked account used in the switcher list.
     *
     * @return array<string, mixed>
     */
    protected function summarize(User $user, LinkedAccount $link): array
    {
        $business = $user->business;

        return [
            'id' => $link->id,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'account_type' => $user->account_type ?? 'business',
            'relation' => $link->relation,
            'is_business_owner' => $user->business?->owner_id === $user->id,
            'role' => $user->relationLoaded('role') && $user->role
                ? ['id' => $user->role->id, 'name' => $user->role->name, 'slug' => $user->role->slug]
                : null,
            'business' => $business ? [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
                'logo_path' => $business->logo_path,
                'status' => $business->status,
                'subscription_status' => $business->relationLoaded('subscription') && $business->subscription
                    ? $business->subscription->status?->value
                    : null,
            ] : null,
        ];
    }
}
