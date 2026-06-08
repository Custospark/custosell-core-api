<?php

namespace App\Services\Platform;

use App\Models\Business;
use App\Models\PlatformAuditLog;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PlatformBusinessService
{
    public function __construct(
        protected PlatformNotificationService $notifications,
        protected PlatformAuditService $audit,
    ) {}

    public function activityWindowDays(): int
    {
        return (int) config('platform.activity_window_days', 30);
    }

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $windowStart = now()->subDays($this->activityWindowDays());
        $today = now()->toDateString();
        $sevenDaysAgo = now()->subDays(6)->startOfDay();

        $query = Business::query()
            ->with(['owner:id,name,email', 'subscription.plan:id,name'])
            ->withCount('users as staff_count')
            ->select('businesses.*')
            ->selectSub(function ($sub) use ($today) {
                $sub->from('sales')
                    ->selectRaw('COALESCE(SUM(total_amount), 0)')
                    ->whereColumn('sales.business_id', 'businesses.id')
                    ->whereDate('sale_date', $today);
            }, 'revenue_today_gross')
            ->selectSub(function ($sub) use ($today) {
                $sub->from('sale_items')
                    ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                    ->selectRaw('COALESCE(SUM(sale_items.refunded_amount), 0)')
                    ->whereColumn('sales.business_id', 'businesses.id')
                    ->whereDate('sales.sale_date', $today);
            }, 'revenue_today_refunds')
            ->selectSub(function ($sub) use ($sevenDaysAgo) {
                $sub->from('sales')
                    ->selectRaw('COALESCE(SUM(total_amount), 0)')
                    ->whereColumn('sales.business_id', 'businesses.id')
                    ->where('sale_date', '>=', $sevenDaysAgo);
            }, 'revenue_7d_gross')
            ->selectSub(function ($sub) use ($sevenDaysAgo) {
                $sub->from('sale_items')
                    ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                    ->selectRaw('COALESCE(SUM(sale_items.refunded_amount), 0)')
                    ->whereColumn('sales.business_id', 'businesses.id')
                    ->where('sales.sale_date', '>=', $sevenDaysAgo);
            }, 'revenue_7d_refunds')
            ->selectSub(function ($sub) use ($windowStart) {
                $sub->from('sales')
                    ->selectRaw('COALESCE(SUM(total_amount), 0)')
                    ->whereColumn('sales.business_id', 'businesses.id')
                    ->where('sale_date', '>=', $windowStart);
            }, 'revenue_30d_gross')
            ->selectSub(function ($sub) use ($windowStart) {
                $sub->from('sale_items')
                    ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                    ->selectRaw('COALESCE(SUM(sale_items.refunded_amount), 0)')
                    ->whereColumn('sales.business_id', 'businesses.id')
                    ->where('sales.sale_date', '>=', $windowStart);
            }, 'revenue_30d_refunds')
            ->selectSub(function ($sub) {
                $sub->from('sales')
                    ->selectRaw('COALESCE(SUM(total_amount), 0)')
                    ->whereColumn('sales.business_id', 'businesses.id');
            }, 'revenue_all_time_gross')
            ->selectSub(function ($sub) {
                $sub->from('sale_items')
                    ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                    ->selectRaw('COALESCE(SUM(sale_items.refunded_amount), 0)')
                    ->whereColumn('sales.business_id', 'businesses.id');
            }, 'revenue_all_time_refunds')
            ->selectSub(function ($sub) use ($windowStart) {
                $sub->from('sales')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('sales.business_id', 'businesses.id')
                    ->where('sale_date', '>=', $windowStart);
            }, 'transactions_30d')
            ->selectSub(function ($sub) {
                $sub->from('sales')
                    ->selectRaw('MAX(sale_date)')
                    ->whereColumn('sales.business_id', 'businesses.id');
            }, 'last_sale_at')
            ->selectSub(function ($sub) {
                $sub->from('users')
                    ->selectRaw('MAX(last_login_at)')
                    ->whereColumn('users.business_id', 'businesses.id');
            }, 'last_user_login_at');

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($search) {
                $q->where('businesses.name', 'like', $search)
                    ->orWhere('businesses.email', 'like', $search)
                    ->orWhereHas('owner', fn ($oq) => $oq->where('email', 'like', $search));
            });
        }

        if (! empty($filters['status'])) {
            $query->where('businesses.status', $filters['status']);
        }

        if (! empty($filters['currency'])) {
            $query->where('businesses.currency', $filters['currency']);
        }

        $sort = $filters['sort'] ?? 'revenue_30d';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($sort === 'name') {
            $query->orderBy('businesses.name', $direction);
        } elseif ($sort === 'created_at') {
            $query->orderBy('businesses.created_at', $direction);
        } else {
            $query->orderByRaw("(revenue_30d_gross - revenue_30d_refunds) {$direction}");
        }

        $paginator = $query->paginate($perPage);

        $paginator->getCollection()->transform(fn (Business $business) => $this->transformBusiness($business, $windowStart));

        return $paginator;
    }

    /** @return array<string, mixed> */
    public function transformBusiness(Business $business, ?Carbon $windowStart = null): array
    {
        $windowStart ??= now()->subDays($this->activityWindowDays());

        $lastSaleAt = $business->last_sale_at ? Carbon::parse($business->last_sale_at) : null;
        $lastLoginAt = $business->last_user_login_at ? Carbon::parse($business->last_user_login_at) : null;
        $lastActivityAt = collect([$lastSaleAt, $lastLoginAt])->filter()->max();

        $hasSalesEver = Sale::where('business_id', $business->id)->exists();
        $hasRecentSale = $lastSaleAt && $lastSaleAt->gte($windowStart);
        $hasRecentLogin = $lastLoginAt && $lastLoginAt->gte($windowStart);

        $activityStatus = 'dormant';
        if ($business->status === 'suspended') {
            $activityStatus = 'suspended';
        } elseif (! $hasSalesEver && ! $lastLoginAt) {
            $activityStatus = 'never_used';
        } elseif ($hasRecentSale || $hasRecentLogin) {
            $activityStatus = 'active';
        }

        return [
            'id' => $business->id,
            'name' => $business->name,
            'slug' => $business->slug,
            'email' => $business->email,
            'currency' => $business->currency,
            'status' => $business->status,
            'activity_status' => $activityStatus,
            'owner_name' => $business->owner?->name,
            'owner_email' => $business->owner?->email,
            'plan_name' => $business->subscription?->plan?->name,
            'subscription_status' => $business->subscription?->status,
            'trial_ends_at' => $business->trial_ends_at?->toIso8601String(),
            'staff_count' => (int) ($business->staff_count ?? 0),
            'revenue_today' => $this->net((float) $business->revenue_today_gross, (float) $business->revenue_today_refunds),
            'revenue_7d' => $this->net((float) $business->revenue_7d_gross, (float) $business->revenue_7d_refunds),
            'revenue_30d' => $this->net((float) $business->revenue_30d_gross, (float) $business->revenue_30d_refunds),
            'revenue_all_time' => $this->net((float) $business->revenue_all_time_gross, (float) $business->revenue_all_time_refunds),
            'transactions_30d' => (int) ($business->transactions_30d ?? 0),
            'last_activity_at' => $lastActivityAt?->toIso8601String(),
            'created_at' => $business->created_at?->toIso8601String(),
        ];
    }

    public function updateStatus(User $actor, Business $business, string $status, ?string $reason): Business
    {
        $business->update(['status' => $status]);

        $this->audit->log($actor, $status === 'suspended' ? 'business.suspended' : 'business.reactivated', 'business', $business->id, $reason);

        $this->notifications->notifyBusinessStatusChange(
            $business->name,
            $business->owner?->email,
            $business->email,
            $status,
            $reason,
        );

        return $business->fresh(['owner', 'subscription.plan']);
    }

    private function net(float $gross, float $refunds): string
    {
        return number_format(max(0, $gross - $refunds), 2, '.', '');
    }
}
