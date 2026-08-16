<?php

namespace App\Services;

use App\Models\LinkedAccount;
use App\Models\User;
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
    ) {}

    /**
     * Authenticate the account to link against real credentials and create the
     * link. The first link a user creates becomes the primary account.
     *
     * @return array{relation: string, linked_account: array<string, mixed>}
     */
    public function link(int $ownerUserId, string $email, string $password): array
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

        $existing = LinkedAccount::query()
            ->where('owner_user_id', $ownerUserId)
            ->where('linked_user_id', $target->id)
            ->first();

        if ($existing) {
            return ['relation' => $existing->relation, 'linked_account' => $this->summarize($target, $existing)];
        }

        $hasAny = LinkedAccount::query()
            ->where('owner_user_id', $ownerUserId)
            ->exists();

        $relation = $hasAny ? self::RELATION_SECONDARY : self::RELATION_PRIMARY;

        $link = DB::transaction(function () use ($ownerUserId, $target, $relation) {
            return LinkedAccount::create([
                'owner_user_id' => $ownerUserId,
                'linked_user_id' => $target->id,
                'relation' => $relation,
            ]);
        });

        Log::info('[LinkedAccounts] account linked', [
            'owner_user_id' => $ownerUserId,
            'linked_user_id' => $target->id,
            'relation' => $relation,
        ]);

        return ['relation' => $relation, 'linked_account' => $this->summarize($target, $link)];
    }

    /**
     * List every account the owner can switch to, ordered with the primary first.
     *
     * @return array<string, mixed>
     */
    public function listFor(int $ownerUserId): array
    {
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
     */
    public function switchTo(int $ownerUserId, int $linkedUserId): array
    {
        $link = $this->linkOrFail($ownerUserId, $linkedUserId);

        $user = User::query()
            ->with(['role', 'roles', 'business.subscription.plan', 'business.subscription.referral.referralCode', 'location', 'locations'])
            ->findOrFail($linkedUserId);

        return [
            'user' => (new UserResource($user))->resolve(),
        ];
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
     * Unlink a secondary account. The primary cannot be removed until another
     * account is promoted to primary first.
     */
    public function unlink(int $ownerUserId, int $linkedUserId): void
    {
        $link = $this->linkOrFail($ownerUserId, $linkedUserId);

        if ($link->relation === self::RELATION_PRIMARY) {
            throw ValidationException::withMessages([
                'linked_account' => 'Set another account as your default before removing this one.',
            ]);
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
