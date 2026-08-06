<?php

namespace App\Services\WebPush;

use App\Models\PushSubscription;
use App\Services\WebPush\Contracts\WebPushServiceInterface;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class WebPushService implements WebPushServiceInterface
{
    public function isEnabled(): bool
    {
        return (bool) config('webpush.enabled')
            && (string) config('webpush.public_key') !== ''
            && (string) config('webpush.private_key') !== '';
    }

    public function register(int $userId, ?int $businessId, array $payload): void
    {
        $endpoint = $payload['endpoint'] ?? '';
        $keys = $payload['keys'] ?? [];

        if ($endpoint === '' || ($keys['p256dh'] ?? '') === '' || ($keys['auth'] ?? '') === '') {
            return;
        }

        PushSubscription::updateOrCreate(
            ['user_id' => $userId, 'endpoint' => $endpoint],
            [
                'business_id' => $businessId,
                'public_key' => $keys['p256dh'],
                'auth_secret' => $keys['auth'],
                'user_agent' => substr((string) request()->header('User-Agent'), 0, 512),
            ],
        );
    }

    public function removeForEndpoint(int $userId, string $endpoint): void
    {
        PushSubscription::query()
            ->where('user_id', $userId)
            ->where('endpoint', $endpoint)
            ->delete();
    }

    public function countForUser(int $userId): int
    {
        return (int) PushSubscription::query()->where('user_id', $userId)->count();
    }

    public function sendToUser(int $userId, string $title, string $body, array $options = []): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $subscriptions = PushSubscription::query()
            ->where('user_id', $userId)
            ->whereNotNull('public_key')
            ->whereNotNull('auth_secret')
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $payload = [
            'title' => $title,
            'body' => $body,
            'url' => $options['url'] ?? (string) config('webpush.route'),
            'icon' => $options['icon'] ?? (string) config('webpush.icon'),
        ];

        if (isset($options['tag'])) {
            $payload['tag'] = $options['tag'];
        }

        try {
            $webPush = new WebPush($this->vapidConfig(), ['TTL' => 120, 'urgency' => 'low']);

            foreach ($subscriptions as $subscription) {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'contentEncoding' => 'aes128gcm',
                        'publicKey' => $subscription->public_key,
                        'authToken' => $subscription->auth_secret,
                    ]),
                    json_encode($payload),
                );
            }

            foreach ($webPush->flush() as $report) {
                if (! $report->isSuccess()) {
                    if ($report->isSubscriptionExpired()) {
                        PushSubscription::query()
                            ->where('id', $this->reportSubscriptionId($report->getEndpoint()))
                            ->delete();
                    } else {
                        Log::warning('Web push delivery failed', [
                            'endpoint' => $report->getEndpoint(),
                            'reason' => $report->getReason(),
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::error('Web push dispatch failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array{VAPID: array{subject: string, publicKey: string, privateKey: string}} */
    private function vapidConfig(): array
    {
        return [
            'VAPID' => [
                'subject' => (string) config('webpush.subject', 'mailto:info@custospark.com'),
                'publicKey' => (string) config('webpush.public_key'),
                'privateKey' => (string) config('webpush.private_key'),
            ],
        ];
    }

    private function reportSubscriptionId(string $endpoint): int
    {
        return (int) PushSubscription::query()->where('endpoint', $endpoint)->value('id');
    }
}