<?php

namespace App\Services\Platform;

use App\Models\Business;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PlatformBusinessService
{
    public function __construct(
        protected PlatformBusinessQueryBuilder $queries,
        protected PlatformBusinessMetricsService $metrics,
        protected PlatformBusinessAdminService $admin,
    ) {}

    public function activityWindowDays(): int
    {
        return (int) config('platform.activity_window_days', 30);
    }

    public function activityDormantDays(): int
    {
        return max($this->activityWindowDays(), (int) config('platform.activity_dormant_days', 90));
    }

    /** @return list<string> */
    public function allowedStatuses(): array
    {
        return config('platform.business_statuses', ['active', 'warning', 'restricted', 'suspended']);
    }

    /** @return list<string> */
    public function blockedStatuses(): array
    {
        return config('platform.blocked_business_statuses', ['restricted', 'suspended']);
    }

    /** @return list<string> */
    public function notificationIntentions(): array
    {
        return config('platform.notification_intentions', ['announcement', 'custom']);
    }

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $windowStart = now()->subDays($this->activityWindowDays());

        $query = $this->queries->businessMetricsQuery($windowStart);

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

        if (! empty($filters['subscription_status'])) {
            $status = $filters['subscription_status'];
            if ($status === 'none') {
                $query->whereDoesntHave('subscription');
            } else {
                $query->whereHas('subscription', fn ($q) => $q->where('status', $status));
            }
        }

        $sort = $filters['sort'] ?? 'gross_sales_30d';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($sort === 'name') {
            $query->orderBy('businesses.name', $direction);
        } elseif ($sort === 'created_at') {
            $query->orderBy('businesses.created_at', $direction);
        } else {
            $query->orderBy('gross_sales_30d', $direction);
        }

        $paginator = $query->paginate($perPage);
        $this->queries->hydrateOwners($paginator->getCollection());
        $paginator->getCollection()->transform(fn (Business $business) => $this->metrics->transformBusiness($business, $windowStart));

        return $paginator;
    }

    public function businessesWithGrossSales30d(?Carbon $windowStart = null): Collection
    {
        return $this->metrics->businessesWithGrossSales30d($windowStart);
    }

    public function countBusinessesWithAttributedSalesOnDate(string $date): int
    {
        return $this->queries->countBusinessesWithAttributedSalesOnDate($date);
    }

    public function grossIncomeDistribution(?Carbon $windowStart = null, int $tierCount = 5): array
    {
        return $this->metrics->grossIncomeDistribution($windowStart, $tierCount);
    }

    public function transformBusiness(Business $business, ?Carbon $windowStart = null): array
    {
        return $this->metrics->transformBusiness($business, $windowStart);
    }

    public function onboardingDashboard(?Carbon $rangeFrom = null, ?Carbon $rangeTo = null): array
    {
        return $this->metrics->onboardingDashboard($rangeFrom, $rangeTo);
    }

    public function updateStatus(
        User $actor,
        Business $business,
        string $status,
        string $reason,
        string $channel = 'both',
    ): Business
    {
        return $this->admin->updateStatus($actor, $business, $status, $reason, $channel);
    }

    public function bulkUpdateStatus(
        User $actor,
        array $ids,
        string $status,
        string $reason,
        string $channel = 'both',
    ): int {
        return $this->admin->bulkUpdateStatus($actor, $ids, $status, $reason, $channel);
    }

    public function delete(User $actor, Business $business, string $reason): void
    {
        $this->admin->delete($actor, $business, $reason);
    }

    public function resetBusinessData(User $actor, Business $business): array
    {
        return $this->admin->resetBusinessData($actor, $business);
    }

    public function bulkDelete(User $actor, array $ids, string $reason): int
    {
        return $this->admin->bulkDelete($actor, $ids, $reason);
    }

    public function notify(
        User $actor,
        array $businessIds,
        string $intention,
        string $message,
        ?string $subject = null,
        bool $markAsNotified = false,
        string $channel = 'both',
    ): int {
        return $this->admin->notify($actor, $businessIds, $intention, $message, $subject, $markAsNotified, $channel);
    }

    public function activateSubscription(User $actor, Business $business, int $planId, string $billingCycle = 'monthly'): Subscription
    {
        return $this->admin->activateSubscription($actor, $business, $planId, $billingCycle);
    }
}
