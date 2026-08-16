<?php

namespace App\Services;

use App\Models\LinkedAccount;
use App\Models\LinkedAccountCluster;
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
 * without logging out - Google-style: link many accounts (2, 3, 4+), and every
 * member of the cluster can switch to every other member.
 *
 * Model: a cluster is one linked set. Linking a new account adds it to the
 * whole cluster, so no matter which account is active, the switcher shows all
 * of them. Each account keeps its own logged-in self as primary by default.
 *
 * Security: linking and unlinking both require a one-time security code sent
 * to the account being linked/unlinked - the code confirms the account's
 * owner approves the action. Switching mints a fresh token for the target
 * account (switch = login without password), so each account has its own
 * session and switching never loses the originating account.
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

        if ($this->inSameCluster($ownerUserId, $target->id)) {
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
     * Verify the security code sent to the account being linked and add that
     * account to the cluster. The first link creates a cluster; later links
     * join the existing cluster so every member can switch to every other.
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

        $link = DB::transaction(function () use ($ownerUserId, $targetUserId) {
            // Owner's cluster: create one on the first link (owner = primary).
            $clusterId = $this->clusterIdFor($ownerUserId);
            if (!$clusterId) {
                $cluster = LinkedAccountCluster::create();
                $clusterId = $cluster->id;
                LinkedAccount::create([
                    'cluster_id' => $clusterId,
                    'user_id' => $ownerUserId,
                    'is_primary' => true,
                ]);
            }

            // If the target already belongs to another cluster, merge that
            // cluster into the owner's so every account stays in exactly one.
            $targetClusterId = $this->clusterIdFor($targetUserId);
            if ($targetClusterId && $targetClusterId !== $clusterId) {
                LinkedAccount::query()
                    ->where('cluster_id', $targetClusterId)
                    ->update(['cluster_id' => $clusterId]);
                LinkedAccountCluster::query()->whereKey($targetClusterId)->delete();
            }

            // Ensure the owner is a member (primary).
            if (!$this->membershipFor($clusterId, $ownerUserId)) {
                LinkedAccount::create([
                    'cluster_id' => $clusterId,
                    'user_id' => $ownerUserId,
                    'is_primary' => true,
                ]);
            }

            return LinkedAccount::create([
                'cluster_id' => $clusterId,
                'user_id' => $targetUserId,
                'is_primary' => false,
            ]);
        });

        Log::info('[LinkedAccounts] account linked to cluster', [
            'cluster_id' => $link->cluster_id,
            'user_id' => $targetUserId,
        ]);

        return [
            'relation' => self::RELATION_SECONDARY,
            'linked_account' => $this->summarize($target, $link),
        ];
    }

    /**
     * List every account in the owner's cluster, ordered primary first. Every
     * member appears no matter which account is active - so switching between
     * any of them never loses the others.
     *
     * @return array<string, mixed>
     */
    public function listFor(int $ownerUserId): array
    {
        $clusterId = $this->clusterIdFor($ownerUserId);
        if (!$clusterId) {
            return ['primary' => null, 'accounts' => []];
        }

        $members = LinkedAccount::query()
            ->where('cluster_id', $clusterId)
            ->with(['user.business.subscription.plan'])
            ->orderByRaw('is_primary DESC')
            ->orderBy('created_at')
            ->get();

        $accounts = $members
            ->map(fn (LinkedAccount $link) => $this->summarize($link->user, $link))
            ->values()
            ->all();

        return [
            'primary' => $accounts[0] ?? null,
            'accounts' => $accounts,
        ];
    }

    /**
     * Switch to a linked account - returns the full auth payload for that
     * account (same shape as /auth/me) plus a fresh bearer token minted for
     * the target account, exactly like a normal login (but without requiring
     * email/password - the owner already proved who they are).
     *
     * @return array{user: array<string, mixed>, token: string}
     */
    public function switchTo(int $ownerUserId, int $linkedUserId): array
    {
        if ($ownerUserId === $linkedUserId) {
            throw ValidationException::withMessages([
                'linked_account' => 'You are already signed in to this account.',
            ]);
        }

        if (!$this->inSameCluster($ownerUserId, $linkedUserId)) {
            throw ValidationException::withMessages([
                'linked_account' => 'This account is not linked.',
            ]);
        }

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

        // Mint a fresh token for the target account (like login). The
        // originating account's token stays valid so they can switch back.
        $token = $user->createToken('auth-token')->plainTextToken;

        return [
            'user' => (new UserResource($user))->resolve(),
            'token' => $token,
        ];
    }

    /**
     * Promote a linked account to primary within the cluster; the previous
     * primary demotes.
     */
    public function setPrimary(int $ownerUserId, int $linkedUserId): void
    {
        $clusterId = $this->clusterIdFor($ownerUserId);
        if (!$clusterId || !$this->membershipFor($ownerUserId, $linkedUserId)) {
            throw ValidationException::withMessages([
                'linked_account' => 'This account is not linked.',
            ]);
        }

        DB::transaction(function () use ($clusterId, $linkedUserId) {
            LinkedAccount::query()
                ->where('cluster_id', $clusterId)
                ->update(['is_primary' => false]);

            LinkedAccount::query()
                ->where('cluster_id', $clusterId)
                ->where('user_id', $linkedUserId)
                ->update(['is_primary' => true]);
        });
    }

    /**
     * Issue a security code to the account being unlinked; the unlink only
     * happens after confirmUnlink() verifies it.
     */
    public function initiateUnlink(int $ownerUserId, int $linkedUserId, ?string $ip, ?string $userAgent): array
    {
        $link = $this->membershipOrFail($ownerUserId, $linkedUserId);

        if ($link->is_primary) {
            throw ValidationException::withMessages([
                'linked_account' => 'Set another account as your default before removing this one.',
            ]);
        }

        $this->verificationService->issue(
            $link->user,
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
     * Verify the code sent to the account being unlinked, then remove it from
     * the cluster. The account is removed for everyone - it no longer appears
     * in any member's switcher.
     */
    public function confirmUnlink(int $ownerUserId, int $linkedUserId, string $code): void
    {
        $link = $this->membershipOrFail($ownerUserId, $linkedUserId);

        if ($link->is_primary) {
            throw ValidationException::withMessages([
                'linked_account' => 'Set another account as your default before removing this one.',
            ]);
        }

        $context = $this->verificationService->verify(
            $link->user,
            AccountVerificationServiceInterface::PURPOSE_UNLINK_ACCOUNT,
            $code,
        );

        if (!$context || (int) ($context['linked_user_id'] ?? 0) !== $linkedUserId) {
            throw ValidationException::withMessages(['code' => 'That security code is invalid or has expired.']);
        }

        $clusterId = $link->cluster_id;

        $link->delete();

        // If the cluster is now empty, drop it entirely.
        $remaining = LinkedAccount::query()->where('cluster_id', $clusterId)->count();
        if ($remaining === 0) {
            LinkedAccountCluster::query()->whereKey($clusterId)->delete();
        }

        Log::info('[LinkedAccounts] account unlinked from cluster', [
            'cluster_id' => $clusterId,
            'user_id' => $linkedUserId,
        ]);
    }

    /** Mirror AuthController::reconcileSubscription. */
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

    protected function clusterIdFor(int $userId): ?int
    {
        $membership = LinkedAccount::query()
            ->where('user_id', $userId)
            ->orderByDesc('is_primary')
            ->first();

        return $membership?->cluster_id;
    }

    protected function membershipFor(int $clusterIdOrOwnerUserId, int $userId): ?LinkedAccount
    {
        return LinkedAccount::query()
            ->where('user_id', $userId)
            ->where('cluster_id', $clusterIdOrOwnerUserId)
            ->first();
    }

    protected function membershipOrFail(int $ownerUserId, int $linkedUserId): LinkedAccount
    {
        $clusterId = $this->clusterIdFor($ownerUserId);
        $link = $clusterId ? $this->membershipFor($clusterId, $linkedUserId) : null;

        if (!$link) {
            throw ValidationException::withMessages([
                'linked_account' => 'This account is not linked.',
            ]);
        }

        return $link;
    }

    protected function inSameCluster(int $aUserId, int $bUserId): bool
    {
        $aCluster = $this->clusterIdFor($aUserId);
        if (!$aCluster) {
            return false;
        }

        return LinkedAccount::query()
            ->where('cluster_id', $aCluster)
            ->where('user_id', $bUserId)
            ->exists();
    }

    /** Small summary of a linked account used in the switcher list. */
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
            'relation' => $link->is_primary ? self::RELATION_PRIMARY : self::RELATION_SECONDARY,
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
