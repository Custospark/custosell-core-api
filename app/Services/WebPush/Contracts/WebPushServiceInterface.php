<?php

namespace App\Services\WebPush\Contracts;

interface WebPushServiceInterface
{
    public function isEnabled(): bool;

    /** @param  array{endpoint: string, keys: array{p256dh: string, auth: string}}  $payload */
    public function register(int $userId, ?int $businessId, array $payload): void;

    public function removeForEndpoint(int $userId, string $endpoint): void;

    public function countForUser(int $userId): int;

    /** @param  array{url?: string, icon?: string, tag?: string}  $options */
    public function sendToUser(int $userId, string $title, string $body, array $options = []): void;
}