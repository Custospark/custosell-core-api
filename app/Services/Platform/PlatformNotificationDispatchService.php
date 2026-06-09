<?php

namespace App\Services\Platform;

use App\Models\Business;
use App\Models\PlatformNotificationDispatch;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PlatformNotificationDispatchService
{
    /** @param  list<array<string, mixed>>  $recipients */
    public function recordMessage(
        ?User $actor,
        string $targetKind,
        string $intention,
        string $message,
        string $channel,
        array $recipients,
        ?string $subject = null,
        bool $markAsNotified = false,
        ?array $metadata = null,
    ): PlatformNotificationDispatch {
        return PlatformNotificationDispatch::create([
            'actor_id' => $actor?->id,
            'dispatch_type' => 'message',
            'target_kind' => $targetKind,
            'intention' => $intention,
            'subject' => $subject,
            'message' => $message,
            'channel' => $channel,
            'mark_as_notified' => $markAsNotified,
            'recipient_count' => count($recipients),
            'recipients' => $recipients,
            'metadata' => $metadata,
        ]);
    }

    /** @param  list<array<string, mixed>>  $recipients */
    public function recordStatusChange(
        ?User $actor,
        string $targetKind,
        string $message,
        string $channel,
        ?string $statusFrom,
        string $statusTo,
        array $recipients,
        ?string $intention = null,
        ?array $metadata = null,
    ): PlatformNotificationDispatch {
        return PlatformNotificationDispatch::create([
            'actor_id' => $actor?->id,
            'dispatch_type' => 'status_change',
            'target_kind' => $targetKind,
            'intention' => $intention,
            'subject' => null,
            'message' => $message,
            'channel' => $channel,
            'status_from' => $statusFrom,
            'status_to' => $statusTo,
            'recipient_count' => count($recipients),
            'recipients' => $recipients,
            'metadata' => $metadata,
        ]);
    }

    /** @return array<string, mixed> */
    public function recipientFromUser(User $user): array
    {
        return [
            'type' => 'user',
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'business_id' => $user->business_id,
            'business_name' => $user->relationLoaded('business') ? $user->business?->name : null,
        ];
    }

    /** @return array<string, mixed> */
    public function recipientFromBusiness(Business $business, ?int $inAppRecipientCount = null): array
    {
        return [
            'type' => 'business',
            'id' => $business->id,
            'name' => $business->name,
            'email' => $business->email,
            'owner_name' => $business->relationLoaded('owner') ? $business->owner?->name : null,
            'owner_email' => $business->relationLoaded('owner') ? $business->owner?->email : null,
            'in_app_recipient_count' => $inAppRecipientCount,
        ];
    }

    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = PlatformNotificationDispatch::query()
            ->with('actor:id,name,email')
            ->orderByDesc('created_at');

        if (! empty($filters['target_kind'])) {
            $query->where('target_kind', $filters['target_kind']);
        }

        if (! empty($filters['dispatch_type'])) {
            $query->where('dispatch_type', $filters['dispatch_type']);
        }

        if (! empty($filters['intention'])) {
            $query->where('intention', $filters['intention']);
        }

        if (! empty($filters['q'])) {
            $search = '%'.$filters['q'].'%';
            $query->where(function (Builder $q) use ($search): void {
                $q->where('message', 'like', $search)
                    ->orWhere('subject', 'like', $search)
                    ->orWhere('recipients', 'like', $search);
            });
        }

        $paginator = $query->paginate(min(50, max(10, $perPage)));
        $paginator->getCollection()->transform(fn (PlatformNotificationDispatch $row) => $this->transformList($row));

        return $paginator;
    }

    public function find(int $id): ?array
    {
        $row = PlatformNotificationDispatch::query()
            ->with('actor:id,name,email')
            ->find($id);

        return $row ? $this->transformDetail($row) : null;
    }

    public function delete(int $id): bool
    {
        $row = PlatformNotificationDispatch::query()->find($id);

        if (! $row) {
            return false;
        }

        $row->delete();

        return true;
    }

    /** @param  list<int>  $ids */
    public function bulkDelete(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return PlatformNotificationDispatch::query()->whereIn('id', $ids)->delete();
    }

    /** @return array<string, mixed> */
    private function transformList(PlatformNotificationDispatch $row): array
    {
        $recipients = collect($row->recipients ?? []);

        return [
            'id' => $row->id,
            'dispatch_type' => $row->dispatch_type,
            'target_kind' => $row->target_kind,
            'intention' => $row->intention,
            'subject' => $row->subject,
            'message_preview' => $this->preview($row->message),
            'channel' => $row->channel,
            'status_from' => $row->status_from,
            'status_to' => $row->status_to,
            'mark_as_notified' => $row->mark_as_notified,
            'recipient_count' => $row->recipient_count,
            'recipient_summary' => $this->recipientSummary($recipients),
            'actor' => $row->actor ? [
                'id' => $row->actor->id,
                'name' => $row->actor->name,
                'email' => $row->actor->email,
            ] : null,
            'sent_at' => $row->created_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function transformDetail(PlatformNotificationDispatch $row): array
    {
        return [
            ...$this->transformList($row),
            'message' => $row->message,
            'recipients' => $row->recipients ?? [],
            'metadata' => $row->metadata,
        ];
    }

  /** @param  Collection<int, array<string, mixed>>  $recipients */
    private function recipientSummary(Collection $recipients): string
    {
        if ($recipients->isEmpty()) {
            return 'No recipients';
        }

        $first = (string) ($recipients->first()['name'] ?? $recipients->first()['email'] ?? 'Recipient');
        if ($recipients->count() === 1) {
            return $first;
        }

        return "{$first} +".($recipients->count() - 1).' more';
    }

    private function preview(string $message, int $max = 120): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($message)) ?? '';

        if (mb_strlen($normalized) <= $max) {
            return $normalized;
        }

        return mb_substr($normalized, 0, $max - 1).'…';
    }
}
