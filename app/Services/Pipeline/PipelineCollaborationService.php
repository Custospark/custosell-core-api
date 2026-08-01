<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardAnnouncement;
use App\Models\PipelineBoardAnnouncementRead;
use App\Models\PipelineLead;
use App\Models\PipelineLeadActivity;
use App\Models\PipelinePoll;
use App\Models\PipelineReminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class PipelineCollaborationService
{
    public function __construct(
        protected PipelineBoardService $boards,
        protected PipelineBoardPermissionService $permission,
        protected PipelineLeadService $leads,
        protected PipelineNotificationService $notifier,
        protected PipelineCollaborationReactionService $reactions,
        protected PipelineBoardAnnouncementService $announcements,
        protected PipelineBoardPollService $polls,
        protected PipelineReminderService $reminders,
    ) {}

    /** @return array{likes: int, dislikes: int, user_reaction: string|null} */
    public function reactionSummary(PipelineLeadActivity $activity, User $viewer): array
    {
        return $this->reactions->reactionSummary($activity, $viewer);
    }

    public function toggleReaction(
        int $businessId,
        User $user,
        int $activityId,
        ?string $reaction,
    ): array {
        return $this->reactions->toggleReaction($businessId, $user, $activityId, $reaction);
    }

    /** @param  list<int>  $userIds */
    public function syncLeadAssignees(PipelineLead $lead, array $userIds, int $assignedBy): void
    {
        $this->reactions->syncLeadAssignees($lead, $userIds, $assignedBy);
    }

    /** @return list<array<string, mixed>> */
    public function listAnnouncements(int $businessId, User $user, int $boardId): array
    {
        return $this->announcements->listAnnouncements($businessId, $user, $boardId);
    }

    public function createAnnouncement(
        int $businessId,
        User $user,
        int $boardId,
        string $title,
        string $body,
        bool $isPinned = false,
    ): array {
        return $this->announcements->createAnnouncement($businessId, $user, $boardId, $title, $body, $isPinned);
    }

    /** @return array<string, mixed> */
    public function setAnnouncementReadState(
        int $businessId,
        User $user,
        int $announcementId,
        bool $isRead,
    ): array {
        return $this->announcements->setAnnouncementReadState($businessId, $user, $announcementId, $isRead);
    }

    /** @return array<string, int> */
    public function boardCollaborationSummary(int $businessId, User $user, int $boardId): array
    {
        $board = $this->boards->getBoard($businessId, $user, $boardId);

        $announcementIds = \App\Models\PipelineBoardAnnouncement::query()
            ->where('board_id', $board->id)
            ->whereNotIn('id', $this->announcements->dismissedAnnouncementIdsForUser($user->id, $board->id))
            ->pluck('id');

        $announcementsCount = $announcementIds->count();
        $readIds = PipelineBoardAnnouncementRead::query()
            ->where('user_id', $user->id)
            ->whereIn('announcement_id', $announcementIds)
            ->where('is_read', true)
            ->pluck('announcement_id');

        $unreadAnnouncementsCount = $announcementsCount - $readIds->count();

        $activePolls = PipelinePoll::query()
            ->where('board_id', $board->id)
            ->whereNull('lead_id')
            ->whereNotIn('id', $this->polls->dismissedPollIdsForUser($user->id, $board->id))
            ->where(function ($q) {
                $q->whereNull('closes_at')->orWhere('closes_at', '>', now());
            })
            ->with('votes:id,poll_id,user_id')
            ->get(['id']);

        $activePollsCount = $activePolls->count();
        $canContribute = $this->permission->userCanContributeToBoard($user, $board);
        $pollsPendingVoteCount = $canContribute
            ? $activePolls->filter(function (PipelinePoll $poll) use ($user) {
                if ($this->polls->pollIsClosed($poll)) {
                    return false;
                }

                return ! $poll->votes->contains(fn ($vote) => (int) $vote->user_id === (int) $user->id);
            })->count()
            : 0;

        return [
            'announcements_count' => $announcementsCount,
            'unread_announcements_count' => max(0, $unreadAnnouncementsCount),
            'active_polls_count' => $activePollsCount,
            'polls_pending_vote_count' => $pollsPendingVoteCount,
            'attention_count' => max(0, $unreadAnnouncementsCount) + $pollsPendingVoteCount,
            'has_attention' => $unreadAnnouncementsCount > 0 || $pollsPendingVoteCount > 0,
        ];
    }

    public function deleteAnnouncement(int $businessId, User $user, int $announcementId): void
    {
        $this->announcements->deleteAnnouncement($businessId, $user, $announcementId);
    }

    /** @param  list<string>  $options */
    public function createPoll(
        int $businessId,
        User $user,
        int $boardId,
        string $question,
        array $options,
        ?int $leadId = null,
        ?string $closesAt = null,
        string $resultsVisibility = 'team',
    ): array {
        return $this->polls->createPoll($businessId, $user, $boardId, $question, $options, $leadId, $closesAt, $resultsVisibility);
    }

    /** @return list<array<string, mixed>> */
    public function listPolls(int $businessId, User $user, int $boardId, ?int $leadId = null): array
    {
        return $this->polls->listPolls($businessId, $user, $boardId, $leadId);
    }

    /** @return array<string, mixed> */
    public function updatePoll(int $businessId, User $user, int $pollId, array $data): array
    {
        return $this->polls->updatePoll($businessId, $user, $pollId, $data);
    }

    /** @return array<string, mixed> */
    public function votePoll(int $businessId, User $user, int $pollId, int $optionId): array
    {
        return $this->polls->votePoll($businessId, $user, $pollId, $optionId);
    }

    /** @return array<string, mixed> */
    public function removePollVote(
        int $businessId,
        User $user,
        int $pollId,
        ?int $targetUserId = null,
    ): array {
        return $this->polls->removePollVote($businessId, $user, $pollId, $targetUserId);
    }

    public function deletePoll(int $businessId, User $user, int $pollId): void
    {
        $this->polls->deletePoll($businessId, $user, $pollId);
    }

    public function createReminder(
        int $businessId,
        User $user,
        int $leadId,
        string $remindAt,
        ?string $message,
        string $channel = 'both',
        ?int $recipientUserId = null,
    ): PipelineReminder {
        return $this->reminders->createReminder($businessId, $user, $leadId, $remindAt, $message, $channel, $recipientUserId);
    }

    /** @return Collection<int, PipelineReminder> */
    public function listReminders(int $businessId, User $user, int $leadId): Collection
    {
        return $this->reminders->listReminders($businessId, $user, $leadId);
    }

    public function cancelReminder(int $businessId, User $user, int $reminderId): void
    {
        $this->reminders->cancelReminder($businessId, $user, $reminderId);
    }

    public function dispatchDueReminders(): int
    {
        return $this->reminders->dispatchDueReminders();
    }

    /** @return list<User> */
    public function leadNotificationRecipients(PipelineLead $lead, User $actor): array
    {
        return $this->reminders->leadNotificationRecipients($lead, $actor);
    }
}
