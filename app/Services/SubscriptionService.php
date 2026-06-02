<?php

namespace App\Services;

use App\Models\Subscription;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\Contracts\SubscriptionServiceInterface;
use Illuminate\Database\Eloquent\Collection;

class SubscriptionService implements SubscriptionServiceInterface
{
    public function __construct(
        protected SubscriptionRepositoryInterface $subscriptionRepository,
    ) {}

    public function getAll(): Collection
    {
        return $this->subscriptionRepository->all();
    }

    public function getById(int $id): ?Subscription
    {
        return $this->subscriptionRepository->find($id);
    }

    public function getByBusiness(int $businessId): ?Subscription
    {
        return $this->subscriptionRepository->findByBusiness($businessId);
    }

    public function create(array $data): Subscription
    {
        return $this->subscriptionRepository->create($data);
    }

    public function update(int $id, array $data): Subscription
    {
        $subscription = $this->subscriptionRepository->find($id);
        if (!$subscription) {
            throw new \RuntimeException('Subscription not found');
        }
        return $this->subscriptionRepository->update($subscription, $data);
    }

    public function delete(int $id): bool
    {
        $subscription = $this->subscriptionRepository->find($id);
        if (!$subscription) {
            throw new \RuntimeException('Subscription not found');
        }
        return $this->subscriptionRepository->delete($subscription);
    }

    public function getActive(): Collection
    {
        return $this->subscriptionRepository->getActive();
    }
}
