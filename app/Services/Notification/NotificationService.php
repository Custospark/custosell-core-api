<?php

namespace App\Services\Notification;

use App\Mail\StandardEmail;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    private const EMAIL_DEDUPE_HOURS = 24;

    /** @return list<string> */
    public function allowedChannels(): array
    {
        return config('platform.notification_channels', ['email', 'in_app', 'both']);
    }

    public function defaultChannel(): string
    {
        return (string) config('platform.default_notification_channel', 'both');
    }

    public function paginateForUser(User $user, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Notification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('sent_at');

        if (! empty($filters['unread_only'])) {
            $query->whereNull('read_at');
        }

        return $query->paginate($perPage);
    }

    public function unreadCountForUser(User $user): int
    {
        return (int) Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    public function markRead(User $user, int $notificationId): ?Notification
    {
        $notification = Notification::query()
            ->where('user_id', $user->id)
            ->where('id', $notificationId)
            ->first();

        if (! $notification || $notification->read_at) {
            return $notification;
        }

        $notification->update(['read_at' => now()]);

        return $notification->fresh();
    }

    public function markAllRead(User $user): int
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function deleteForUser(User $user, int $notificationId): bool
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->where('id', $notificationId)
            ->delete() > 0;
    }

    public function deleteAllForUser(User $user): int
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->delete();
    }

    public function buildContentDedupeKey(
        ?int $businessId,
        string $type,
        ?string $intention,
        string $title,
        string $plainMessage,
    ): string {
        return hash('sha256', implode('|', [
            (string) ($businessId ?? 0),
            $type,
            $intention ?? '',
            trim($title),
            trim($plainMessage),
        ]));
    }

    public function buildUserDedupeKey(int $userId, string $contentKey): string
    {
        return hash('sha256', $userId.'|'.$contentKey);
    }

    public function persistInAppIfNew(
        int $userId,
        string $title,
        string $plainBody,
        string $type,
        ?int $businessId = null,
        ?string $intention = null,
        ?array $metadata = null,
        ?string $contentKey = null,
    ): bool {
        $contentKey ??= $this->buildContentDedupeKey($businessId, $type, $intention, $title, $plainBody);
        $dedupeKey = $this->buildUserDedupeKey($userId, $contentKey);

        if ($this->hasInAppDuplicate($userId, $dedupeKey)) {
            return false;
        }

        Notification::create([
            'user_id' => $userId,
            'business_id' => $businessId,
            'title' => $title,
            'message' => $plainBody,
            'type' => $type,
            'intention' => $intention,
            'channel' => 'in_app',
            'metadata' => $metadata,
            'dedupe_key' => $dedupeKey,
            'sent_at' => Carbon::now(),
        ]);

        return true;
    }

    public function sendEmailIfNew(
        string $email,
        string $contentKey,
        string $title,
        string $htmlBody,
        ?string $ctaUrl = null,
        ?string $ctaLabel = null,
        ?string $tip = null,
    ): bool {
        if ($email === '') {
            return false;
        }

        $cacheKey = 'notif_email:'.hash('sha256', strtolower($email).'|'.$contentKey);

        if (Cache::has($cacheKey)) {
            return false;
        }

        try {
            Mail::to($email)->send(new StandardEmail(
                title: $title,
                mailBody: $htmlBody,
                ctaUrl: $ctaUrl,
                ctaLabel: $ctaLabel,
                tip: $tip,
                isHtml: true,
            ));

            Cache::put($cacheKey, true, now()->addHours(self::EMAIL_DEDUPE_HOURS));

            return true;
        } catch (\Throwable $e) {
            Log::error('Email dispatch failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendToUser(
        User $user,
        string $title,
        string $body,
        string $type,
        string $channel = 'both',
        ?int $businessId = null,
        ?string $intention = null,
        ?array $metadata = null,
        ?string $ctaUrl = null,
        ?string $ctaLabel = null,
        ?string $tip = null,
    ): void {
        $plainBody = $this->plainTextFromHtml($body);
        $contentKey = $this->buildContentDedupeKey($businessId, $type, $intention, $title, $plainBody);

        if (in_array($channel, ['in_app', 'both'], true)) {
            $this->persistInAppIfNew(
                $user->id,
                $title,
                $plainBody,
                $type,
                $businessId,
                $intention,
                $metadata,
                $contentKey,
            );
        }

        if (in_array($channel, ['email', 'both'], true)) {
            $this->sendEmailIfNew(
                $user->email ?? '',
                $contentKey,
                $title,
                $body,
                $ctaUrl,
                $ctaLabel,
                $tip,
            );
        }
    }

    private function hasInAppDuplicate(int $userId, string $dedupeKey): bool
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->where('dedupe_key', $dedupeKey)
            ->exists();
    }

    public function plainTextFromHtml(string $html): string
    {
        $text = str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $html);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
