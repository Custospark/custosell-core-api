<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineLead;
use App\Models\PipelineLeadAssignee;
use App\Models\PipelineReminder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class PipelineReminderService
{
    public function __construct(
        protected PipelineLeadService $leads,
        protected PipelineBoardPermissionService $permission,
        protected PipelineNotificationService $notifier,
    ) {}

    public function createReminder(
        int $businessId,
        User $user,
        int $leadId,
        string $remindAt,
        ?string $message,
        string $channel = 'both',
        ?int $recipientUserId = null,
    ): PipelineReminder {
        $lead = $this->leads->getLead($businessId, $user, $leadId);
        $this->permission->ensureCanEditBoard($user, $lead->board);
        $recipientId = $recipientUserId ?? $user->id;

        return PipelineReminder::create([
            'business_id' => $businessId,
            'lead_id' => $lead->id,
            'user_id' => $recipientId,
            'created_by' => $user->id,
            'remind_at' => Carbon::parse($remindAt),
            'message' => $message,
            'channel' => in_array($channel, ['in_app', 'email', 'both'], true) ? $channel : 'both',
        ]);
    }

    /** @return Collection<int, PipelineReminder> */
    public function listReminders(int $businessId, User $user, int $leadId): Collection
    {
        $lead = $this->leads->getLead($businessId, $user, $leadId);

        return PipelineReminder::query()
            ->where('lead_id', $lead->id)
            ->whereNull('cancelled_at')
            ->orderBy('remind_at')
            ->get();
    }

    public function cancelReminder(int $businessId, User $user, int $reminderId): void
    {
        $reminder = PipelineReminder::query()
            ->where('business_id', $businessId)
            ->whereKey($reminderId)
            ->with(['lead.board'])
            ->firstOrFail();

        $this->leads->getLead($businessId, $user, (int) $reminder->lead_id);

        if ((int) $reminder->created_by !== (int) $user->id && ! $this->permission->userCanManageBoard($user, $reminder->lead->board)) {
            abort(403, 'You cannot cancel this reminder.');
        }

        $reminder->update(['cancelled_at' => now()]);
    }

    public function dispatchDueReminders(): int
    {
        $due = PipelineReminder::query()
            ->whereNull('sent_at')
            ->whereNull('cancelled_at')
            ->where('remind_at', '<=', now())
            ->with(['lead.board', 'user'])
            ->limit(200)
            ->get();

        $sent = 0;
        foreach ($due as $reminder) {
            $lead = $reminder->lead;
            $board = $lead?->board;
            $recipient = $reminder->user;
            if (! $lead || ! $board || ! $recipient) {
                continue;
            }

            $this->notifier->notifyReminder($recipient, $lead, $board, $reminder->message);
            $reminder->update(['sent_at' => now()]);
            $sent++;
        }

        return $sent;
    }

    /** @return list<User> */
    public function leadNotificationRecipients(PipelineLead $lead, User $actor): array
    {
        $board = $lead->board ?? PipelineBoard::query()->find($lead->board_id);
        if (! $board) {
            return [];
        }

        $ids = collect([$lead->assigned_to, $lead->created_by])
            ->merge(
                PipelineLeadAssignee::query()->where('lead_id', $lead->id)->pluck('user_id'),
            )
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn ($id) => $id === (int) $actor->id)
            ->values()
            ->all();

        if ($ids === []) {
            return $this->notifier->boardRecipientsForNotifications($board, $actor);
        }

        return User::query()->whereIn('id', $ids)->get()->all();
    }
}
