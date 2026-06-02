<?php

namespace App\Services\Contracts;

interface SyncServiceInterface
{
    public function pull(int $businessId, ?string $since): array;

    public function push(int $businessId, array $payload): array;

    public function full(int $businessId): array;
}
