<?php

namespace App\Services\Contracts;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Collection;

interface SubscriptionServiceInterface
{
    public function getAll(): Collection;

    public function getById(int $id): ?Subscription;

    public function getByBusiness(int $businessId): ?Subscription;

    public function create(array $data): Subscription;

    public function update(int $id, array $data): Subscription;

    public function delete(int $id): bool;

    public function getActive(): Collection;
}
