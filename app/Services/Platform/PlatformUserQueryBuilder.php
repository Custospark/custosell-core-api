<?php

namespace App\Services\Platform;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query building for platform users. Keeps search/filtering in one place so the
 * paginated list and the onboarding dashboard share identical filtering rules.
 */
class PlatformUserQueryBuilder
{
    /** Login activity buckets, aligned with the frontend activity filter. */
    protected const ACTIVE_LOGIN_DAYS = 30;

    protected const DORMANT_LOGIN_DAYS = 90;

    public function paginateTenantUsers(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->baseUserQuery($filters)->orderByDesc('created_at')->paginate($perPage);
    }

    /** @return list<User> */
    public function allMatching(array $filters = []): array
    {
        return $this->baseUserQuery($filters)->get()->all();
    }

    protected function baseUserQuery(array $filters): Builder
    {
        $query = User::query()
            ->with(['business:id,name,owner_id,status', 'business.subscription.plan:id,name,slug', 'role:id,name,slug', 'roles:id,name']);

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

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['status_duration_days'])) {
            $query->where(
                'status_changed_at',
                '<=',
                now()->subDays((int) $filters['status_duration_days'])
            );
        }

        $this->applyLoginActivity($query, $filters['login_activity'] ?? null);

        $this->applyBusinessFilter($query, $filters['business'] ?? null, $filters['business_id'] ?? null);

        return $query;
    }

    protected function applyLoginActivity(Builder $query, ?string $activity): void
    {
        if (empty($activity)) {
            return;
        }

        $query->where(function ($q) use ($activity) {
            match ($activity) {
                'active' => $q->where('last_login_at', '>=', now()->subDays(self::ACTIVE_LOGIN_DAYS)),
                'dormant' => $q->whereBetween('last_login_at', [
                    now()->subDays(self::DORMANT_LOGIN_DAYS),
                    now()->subDays(self::ACTIVE_LOGIN_DAYS),
                ]),
                'churned' => $q->where('last_login_at', '<=', now()->subDays(self::DORMANT_LOGIN_DAYS)),
                'never_logged_in' => $q->whereNull('last_login_at'),
                default => null,
            };
        });
    }

    protected function applyBusinessFilter(Builder $query, ?string $business, $businessId): void
    {
        if (! empty($business)) {
            match ($business) {
                'with_business' => $query->whereNotNull('business_id'),
                'no_business' => $query->whereNull('business_id'),
                'platform_admin' => $query->whereHas('roles', fn ($q) => $q->where('name', 'platform-admin')),
                default => null,
            };

            return;
        }

        if (! empty($businessId)) {
            $query->where('business_id', (int) $businessId);
        }
    }
}