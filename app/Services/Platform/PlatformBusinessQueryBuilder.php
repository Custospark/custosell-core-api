<?php

namespace App\Services\Platform;

use App\Models\Business;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the attributed-sales business metrics query and resolves business owners/staff.
 */
class PlatformBusinessQueryBuilder
{
    protected function activityWindowDays(): int
    {
        return (int) config('platform.activity_window_days', 30);
    }

    public function businessMetricsQuery(?Carbon $windowStart = null)
    {
        $windowStart ??= now()->subDays($this->activityWindowDays());
        $today = now()->toDateString();
        $sevenDaysAgo = now()->subDays(6)->startOfDay();

        return Business::query()
            ->with(['owner:id,name,email,phone', 'subscription.plan:id,name'])
            ->select('businesses.*')
            ->selectSub($this->staffCountSubquery(), 'staff_count')
            ->selectSub($this->grossSalesSubquery($today, true), 'gross_sales_today')
            ->selectSub($this->grossSalesSubquery($sevenDaysAgo), 'gross_sales_7d')
            ->selectSub($this->grossSalesSubquery($windowStart), 'gross_sales_30d')
            ->selectSub($this->grossSalesSubquery(), 'gross_sales_all_time')
            ->selectSub($this->attributedSalesCountSubquery($windowStart), 'transactions_30d')
            ->selectSub($this->attributedLastSaleSubquery(), 'last_sale_at')
            ->selectSub($this->linkedUsersLastLoginSubquery(), 'last_user_login_at')
            ->selectSub($this->totalStockSubquery(), 'total_stock');
    }

    public function countBusinessesWithAttributedSalesOnDate(string $date): int
    {
        return (int) Business::query()
            ->whereExists(function ($query) use ($date): void {
                $query->selectRaw('1')
                    ->from('sales')
                    ->whereNull('sales.deleted_at')
                    ->whereDate('sales.sale_date', $date);
                $this->applyAttributedSalesConstraint($query);
            })
            ->count();
    }

    public function hydrateOwners(Collection $businesses): void
    {
        if ($businesses->isEmpty()) {
            return;
        }

        $ownerIds = $businesses->pluck('owner_id')->filter()->unique()->values();
        $emails = $businesses->pluck('email')->filter()->unique()->values();
        $businessIds = $businesses->pluck('id');

        $users = User::query()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($ownerIds, $emails, $businessIds): void {
                if ($ownerIds->isNotEmpty()) {
                    $q->orWhereIn('id', $ownerIds);
                }
                if ($emails->isNotEmpty()) {
                    $q->orWhereIn('email', $emails);
                }
                $q->orWhereIn('business_id', $businessIds);
            })
            ->get(['id', 'name', 'email', 'phone', 'business_id']);

        foreach ($businesses as $business) {
            $owner = $this->pickOwnerUser($business, $users);
            if ($owner) {
                $business->setRelation('owner', $owner);
            }
        }
    }

    public function resolveOwner(Business $business): ?User
    {
        if ($business->relationLoaded('owner') && $business->owner) {
            return $business->owner;
        }

        return $this->pickOwnerUser($business, $this->candidateOwnerUsers($business));
    }

    public function resolveStaffCount(Business $business): int
    {
        $fromQuery = $business->getAttributes()['staff_count'] ?? null;
        if ($fromQuery !== null) {
            return (int) $fromQuery;
        }

        return (int) User::query()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($business): void {
                $q->where('business_id', $business->id);
                if ($business->owner_id) {
                    $q->orWhere('id', $business->owner_id);
                }
                if ($business->email) {
                    $q->orWhere('email', $business->email);
                }
            })
            ->distinct()
            ->count('id');
    }

    public function businessHasAttributedSales(Business $business): bool
    {
        return Sale::query()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($business): void {
                $q->where('business_id', $business->id)
                    ->orWhereIn('user_id', $this->linkedUserIdsForBusiness($business));
            })
            ->exists();
    }

    private function staffCountSubquery(): \Closure
    {
        return function ($sub): void {
            $sub->from('users')
                ->selectRaw('COUNT(DISTINCT users.id)')
                ->whereNull('users.deleted_at')
                ->where(function ($q): void {
                    $q->whereColumn('users.business_id', 'businesses.id')
                        ->orWhereColumn('users.id', 'businesses.owner_id')
                        ->orWhere(function ($q2): void {
                            $q2->whereNotNull('businesses.email')
                                ->whereColumn('users.email', 'businesses.email');
                        });
                });
        };
    }

    /** @return Collection<int, User> */
    private function candidateOwnerUsers(Business $business): Collection
    {
        return User::query()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($business): void {
                if ($business->owner_id) {
                    $q->orWhere('id', $business->owner_id);
                }
                if ($business->email) {
                    $q->orWhere('email', $business->email);
                }
                $q->orWhere('business_id', $business->id);
            })
            ->get(['id', 'name', 'email', 'phone', 'business_id']);
    }

    /** @param Collection<int, User> $users */
    private function pickOwnerUser(Business $business, Collection $users): ?User
    {
        if ($business->owner_id) {
            $byId = $users->firstWhere('id', $business->owner_id);
            if ($byId) {
                return $byId;
            }
        }

        if ($business->email) {
            $byEmail = $users->first(
                fn (User $user): bool => strcasecmp((string) $user->email, (string) $business->email) === 0,
            );
            if ($byEmail) {
                return $byEmail;
            }
        }

        return $users->where('business_id', $business->id)->sortBy('id')->first();
    }

    private function linkedUsersSubquery(): \Closure
    {
        return function ($userQuery): void {
            $userQuery->from('users')
                ->select('users.id')
                ->whereNull('users.deleted_at')
                ->where(function ($q): void {
                    $q->whereColumn('users.business_id', 'businesses.id')
                        ->orWhereColumn('users.id', 'businesses.owner_id')
                        ->orWhere(function ($q2): void {
                            $q2->whereNotNull('businesses.email')
                                ->whereColumn('users.email', 'businesses.email');
                        });
                });
        };
    }

    private function applyAttributedSalesConstraint($query): void
    {
        $query->whereNull('sales.deleted_at')
            ->where(function ($q): void {
                $q->whereColumn('sales.business_id', 'businesses.id')
                    ->orWhereIn('sales.user_id', $this->linkedUsersSubquery());
            });
    }

    private function grossSalesSubquery(Carbon|string|null $since = null, bool $todayOnly = false): \Closure
    {
        return function ($sub) use ($since, $todayOnly): void {
            $sub->from('sales')
                ->selectRaw('COALESCE(SUM(sales.total_amount), 0)');
            $this->applyAttributedSalesConstraint($sub);

            if ($todayOnly && is_string($since)) {
                $sub->whereDate('sales.sale_date', $since);
            } elseif ($since instanceof Carbon) {
                $sub->where('sales.sale_date', '>=', $since);
            }
        };
    }

    private function attributedSalesCountSubquery(Carbon $since): \Closure
    {
        return function ($sub) use ($since): void {
            $sub->from('sales')
                ->selectRaw('COUNT(*)');
            $this->applyAttributedSalesConstraint($sub);
            $sub->where('sales.sale_date', '>=', $since);
        };
    }

    private function totalStockSubquery(): \Closure
    {
        return function ($sub): void {
            $sub->from('products')
                ->selectRaw('COUNT(*)')
                ->whereColumn('products.business_id', 'businesses.id')
                ->whereNull('products.deleted_at');
        };
    }

    private function attributedLastSaleSubquery(): \Closure
    {
        return function ($sub): void {
            $sub->from('sales')
                ->selectRaw('MAX(sales.sale_date)');
            $this->applyAttributedSalesConstraint($sub);
        };
    }

    private function linkedUsersLastLoginSubquery(): \Closure
    {
        return function ($sub): void {
            $sub->from('users')
                ->selectRaw('MAX(users.last_login_at)')
                ->whereNull('users.deleted_at')
                ->where(function ($q): void {
                    $q->whereColumn('users.business_id', 'businesses.id')
                        ->orWhereColumn('users.id', 'businesses.owner_id')
                        ->orWhere(function ($q2): void {
                            $q2->whereNotNull('businesses.email')
                                ->whereColumn('users.email', 'businesses.email');
                        });
                });
        };
    }

    /** @return list<int> */
    private function linkedUserIdsForBusiness(Business $business): array
    {
        return User::query()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($business): void {
                $q->where('business_id', $business->id);
                if ($business->owner_id) {
                    $q->orWhere('id', $business->owner_id);
                }
                if ($business->email) {
                    $q->orWhere('email', $business->email);
                }
            })
            ->pluck('id')
            ->all();
    }
}
