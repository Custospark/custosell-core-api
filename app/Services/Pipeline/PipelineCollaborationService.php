<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineActivityReaction;
use App\Models\PipelineBoard;
use App\Models\PipelineBoardAnnouncement;
use App\Models\PipelineBoardAnnouncementRead;
use App\Models\PipelineLead;
use App\Models\PipelineLeadActivity;
use App\Models\PipelineLeadAssignee;
use App\Models\PipelinePoll;
use App\Models\PipelinePollDismissal;
use App\Models\PipelinePollOption;
use App\Models\PipelinePollVote;
use App\Models\PipelineReminder;
use App\Models\User;
use App\Services\PipelineService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PipelineCollaborationService
{
    public function __construct(
        protected PipelineService $pipeline,
        protected PipelineNotificationService $notifier,
    ) {}

    /** @return array{likes: int, dislikes: int, user_reaction: string|null} */
    public function reactionSummary(PipelineLeadActivity $activity, User $viewer): array
    {
        $counts = PipelineActivityReaction::query()
            ->where('activity_id', $activity->id)
            ->selectRaw("reaction, count(*) as total")
            ->groupBy('reaction')
            ->pluck('total', 'reaction');

        $userReaction = PipelineActivityReaction::query()
            ->where('activity_id', $activity->id)
            ->where('user_id', $viewer->id)
            ->value('reaction');

        return [
            'likes' => (int) ($counts['like'] ?? 0),
            'dislikes' => (int) ($counts['dislike'] ?? 0),
            'user_reaction' => $userReaction,
        ];
    }

    public function toggleReaction(
        int $businessId,
        User $user,
        int $activityId,
        ?string $reaction,
    ): array {
        $activity = PipelineLeadActivity::query()
            ->where('business_id', $businessId)
            ->whereKey($activityId)
            ->firstOrFail();

        if (! in_array($activity->type, ['note', 'comment', 'call', 'email', 'meeting'], true)) {
            abort(422, 'Reactions are only available on comments.');
        }

        $lead = $this->pipeline->getLead($businessId, $user, (int) $activity->lead_id);

        $existing = PipelineActivityReaction::query()
            ->where('activity_id', $activity->id)
            ->where('user_id', $user->id)
            ->first();

        $previousReaction = $existing?->reaction;

        if ($reaction === null || $reaction === '') {
            $existing?->delete();
        } elseif (! in_array($reaction, ['like', 'dislike'], true)) {
            abort(422, 'Reaction must be like or dislike.');
        } elseif ($existing && $existing->reaction === $reaction) {
            $existing->delete();
        } elseif ($existing) {
            $existing->update(['reaction' => $reaction]);
        } else {
            PipelineActivityReaction::create([
                'activity_id' => $activity->id,
                'user_id' => $user->id,
                'reaction' => $reaction,
            ]);
        }

        $currentReaction = PipelineActivityReaction::query()
            ->where('activity_id', $activity->id)
            ->where('user_id', $user->id)
            ->value('reaction');

        $preview = $activity->body ? mb_substr($activity->body, 0, 120) : null;

        if ($currentReaction && $currentReaction !== $previousReaction) {
            $label = $currentReaction === 'like' ? 'Liked a comment' : 'Disliked a comment';
            $this->pipeline->logLeadHistoryEvent($lead, $user, $label, [
                'action' => 'reaction',
                'reaction' => $currentReaction,
                'target_activity_id' => $activity->id,
                'preview' => $preview,
            ]);
        } elseif (! $currentReaction && $previousReaction) {
            $this->pipeline->logLeadHistoryEvent($lead, $user, 'Removed reaction', [
                'action' => 'reaction_removed',
                'reaction' => $previousReaction,
                'target_activity_id' => $activity->id,
                'preview' => $preview,
            ]);
        }

        return $this->reactionSummary($activity, $user);
    }

    /** @param  list<int>  $userIds */
    public function syncLeadAssignees(PipelineLead $lead, array $userIds, int $assignedBy): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $existing = PipelineLeadAssignee::query()
            ->where('lead_id', $lead->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $toAdd = array_diff($userIds, $existing);
        $toRemove = array_diff($existing, $userIds);

        if ($toRemove !== []) {
            PipelineLeadAssignee::query()
                ->where('lead_id', $lead->id)
                ->whereIn('user_id', $toRemove)
                ->delete();
        }

        foreach ($toAdd as $userId) {
            PipelineLeadAssignee::create([
                'lead_id' => $lead->id,
                'user_id' => $userId,
                'assigned_by' => $assignedBy,
            ]);
        }

        $primary = $userIds[0] ?? null;
        if ($lead->assigned_to !== $primary) {
            $lead->update(['assigned_to' => $primary]);
        }
    }

    /** @return list<array<string, mixed>> */
    public function listAnnouncements(int $businessId, User $user, int $boardId): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);

        $items = PipelineBoardAnnouncement::query()
            ->where('board_id', $board->id)
            ->whereNotIn('id', $this->dismissedAnnouncementIdsForUser($user->id, $board->id))
            ->with('creator:id,name,avatar')
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->get();

        return $this->serializeAnnouncements($items, $user, $board);
    }

    public function createAnnouncement(
        int $businessId,
        User $user,
        int $boardId,
        string $title,
        string $body,
        bool $isPinned = false,
    ): array {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);
        $this->assertCanManageBoard($user, $board);

        $announcement = PipelineBoardAnnouncement::create([
            'business_id' => $businessId,
            'board_id' => $board->id,
            'created_by' => $user->id,
            'title' => $title,
            'body' => $body,
            'is_pinned' => $isPinned,
        ]);

        PipelineBoardAnnouncementRead::create([
            'announcement_id' => $announcement->id,
            'user_id' => $user->id,
            'is_read' => true,
            'read_at' => now(),
        ]);

        $recipients = $this->boardRecipients($board, $user);
        $this->notifier->notifyAnnouncement($board, $user, $title, $body, $recipients);

        return $this->serializeAnnouncement($announcement->load('creator:id,name,avatar'), $user, $board);
    }

    /** @return array<string, mixed> */
    public function setAnnouncementReadState(
        int $businessId,
        User $user,
        int $announcementId,
        bool $isRead,
    ): array {
        $announcement = PipelineBoardAnnouncement::query()
            ->where('business_id', $businessId)
            ->whereKey($announcementId)
            ->with('creator:id,name,avatar')
            ->firstOrFail();

        $board = $this->pipeline->getBoard($businessId, $user, (int) $announcement->board_id);

        PipelineBoardAnnouncementRead::updateOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $user->id],
            ['is_read' => $isRead, 'read_at' => $isRead ? now() : null],
        );

        return $this->serializeAnnouncement($announcement, $user, $board);
    }

    /** @return array<string, int> */
    public function boardCollaborationSummary(int $businessId, User $user, int $boardId): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);

        $announcementIds = PipelineBoardAnnouncement::query()
            ->where('board_id', $board->id)
            ->whereNotIn('id', $this->dismissedAnnouncementIdsForUser($user->id, $board->id))
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
            ->whereNotIn('id', $this->dismissedPollIdsForUser($user->id, $board->id))
            ->where(function ($q) {
                $q->whereNull('closes_at')->orWhere('closes_at', '>', now());
            })
            ->with('votes:id,poll_id,user_id')
            ->get(['id']);

        $activePollsCount = $activePolls->count();
        $pollsPendingVoteCount = $activePolls->filter(function (PipelinePoll $poll) use ($user) {
            return ! $poll->votes->contains(fn ($vote) => (int) $vote->user_id === (int) $user->id);
        })->count();

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
        $announcement = PipelineBoardAnnouncement::query()
            ->where('business_id', $businessId)
            ->whereKey($announcementId)
            ->firstOrFail();

        $board = $this->pipeline->getBoard($businessId, $user, (int) $announcement->board_id);

        $canDeleteForAll = (int) $announcement->created_by === (int) $user->id
            || $this->pipeline->userCanManageBoard($user, $board);

        if ($canDeleteForAll) {
            $announcement->delete();

            return;
        }

        $this->dismissAnnouncementForUser($announcement, $user);
    }

    protected function dismissAnnouncementForUser(PipelineBoardAnnouncement $announcement, User $user): void
    {
        PipelineBoardAnnouncementRead::updateOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $user->id],
            [
                'is_read' => true,
                'read_at' => now(),
                'is_dismissed' => true,
                'dismissed_at' => now(),
            ],
        );
    }

    /** @return list<int> */
    protected function dismissedAnnouncementIdsForUser(int $userId, int $boardId): array
    {
        return PipelineBoardAnnouncementRead::query()
            ->where('user_id', $userId)
            ->where('is_dismissed', true)
            ->whereIn('announcement_id', function ($q) use ($boardId) {
                $q->select('id')
                    ->from('pipeline_board_announcements')
                    ->where('board_id', $boardId);
            })
            ->pluck('announcement_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return list<int> */
    protected function dismissedPollIdsForUser(int $userId, int $boardId): array
    {
        return PipelinePollDismissal::query()
            ->where('user_id', $userId)
            ->whereIn('poll_id', function ($q) use ($boardId) {
                $q->select('id')
                    ->from('pipeline_polls')
                    ->where('board_id', $boardId);
            })
            ->pluck('poll_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function dismissPollForUser(PipelinePoll $poll, User $user): void
    {
        PipelinePollDismissal::updateOrCreate(
            ['poll_id' => $poll->id, 'user_id' => $user->id],
            ['dismissed_at' => now()],
        );
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
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);
        $this->assertCanManageBoard($user, $board);

        if ($leadId) {
            $this->pipeline->getLead($businessId, $user, $leadId);
        }

        if (! in_array($resultsVisibility, ['team', 'creator_only'], true)) {
            abort(422, 'Results visibility must be team or creator_only.');
        }

        $poll = DB::transaction(function () use ($businessId, $user, $board, $question, $options, $leadId, $closesAt, $resultsVisibility) {
            $poll = PipelinePoll::create([
                'business_id' => $businessId,
                'board_id' => $board->id,
                'lead_id' => $leadId,
                'created_by' => $user->id,
                'question' => $question,
                'closes_at' => $closesAt ? Carbon::parse($closesAt) : null,
                'results_visibility' => $resultsVisibility,
            ]);

            foreach (array_values($options) as $index => $label) {
                PipelinePollOption::create([
                    'poll_id' => $poll->id,
                    'label' => trim($label),
                    'sort_order' => $index,
                ]);
            }

            return $poll;
        });

        $poll->load(['options', 'creator:id,name,avatar', 'votes']);
        $recipients = $this->boardRecipients($board, $user);
        $this->notifier->notifyPoll($poll, $board, $user, $recipients);

        return $this->serializePoll($poll, $user, $board);
    }

    /** @return list<array<string, mixed>> */
    public function listPolls(int $businessId, User $user, int $boardId, ?int $leadId = null): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);

        $query = PipelinePoll::query()
            ->where('board_id', $board->id)
            ->with(['options', 'creator:id,name,avatar', 'votes'])
            ->orderByDesc('created_at');

        if ($leadId) {
            $query->where('lead_id', $leadId);
        } else {
            $query->whereNull('lead_id');
        }

        $polls = $query->get()->reject(
            fn (PipelinePoll $poll) => in_array((int) $poll->id, $this->dismissedPollIdsForUser($user->id, $board->id), true),
        );

        return $this->serializePolls($polls, $user, $board);
    }

    /** @return array<string, mixed> */
    public function votePoll(int $businessId, User $user, int $pollId, int $optionId): array
    {
        $poll = PipelinePoll::query()
            ->where('business_id', $businessId)
            ->whereKey($pollId)
            ->with('options')
            ->firstOrFail();

        $this->pipeline->getBoard($businessId, $user, (int) $poll->board_id);

        if ($poll->closes_at && $poll->closes_at->isPast()) {
            abort(422, 'This poll is closed.');
        }

        $validOption = $poll->options->contains('id', $optionId);
        if (! $validOption) {
            abort(422, 'Invalid poll option.');
        }

        if (! $poll->allow_multiple) {
            PipelinePollVote::query()
                ->where('poll_id', $poll->id)
                ->where('user_id', $user->id)
                ->delete();
        }

        PipelinePollVote::firstOrCreate([
            'poll_id' => $poll->id,
            'option_id' => $optionId,
            'user_id' => $user->id,
        ]);

        $board = $this->pipeline->getBoard($businessId, $user, (int) $poll->board_id);

        return $this->serializePoll($poll->fresh(['options', 'creator:id,name,avatar', 'votes']), $user, $board);
    }

    /** @return array<string, mixed> */
    public function removePollVote(
        int $businessId,
        User $user,
        int $pollId,
        ?int $targetUserId = null,
    ): array {
        $poll = PipelinePoll::query()
            ->where('business_id', $businessId)
            ->whereKey($pollId)
            ->with(['options', 'creator:id,name,avatar', 'votes'])
            ->firstOrFail();

        $board = $this->pipeline->getBoard($businessId, $user, (int) $poll->board_id);
        $targetUserId = $targetUserId ?? $user->id;
        $canManagePoll = $this->canManagePoll($poll, $user, $board);

        if ((int) $targetUserId !== (int) $user->id && ! $canManagePoll) {
            abort(403, 'You can only remove your own vote.');
        }

        PipelinePollVote::query()
            ->where('poll_id', $poll->id)
            ->where('user_id', $targetUserId)
            ->delete();

        return $this->serializePoll($poll->fresh(['options', 'creator:id,name,avatar', 'votes']), $user, $board);
    }

    public function deletePoll(int $businessId, User $user, int $pollId): void
    {
        $poll = PipelinePoll::query()
            ->where('business_id', $businessId)
            ->whereKey($pollId)
            ->firstOrFail();

        $board = $this->pipeline->getBoard($businessId, $user, (int) $poll->board_id);

        if ($this->canManagePoll($poll, $user, $board)) {
            $poll->delete();

            return;
        }

        $this->dismissPollForUser($poll, $user);
    }

    protected function canManagePoll(PipelinePoll $poll, User $user, PipelineBoard $board): bool
    {
        return (int) $poll->created_by === (int) $user->id
            || $this->pipeline->userCanManageBoard($user, $board);
    }

    /** @param  Collection<int, PipelineBoardAnnouncement>  $items
     * @return list<array<string, mixed>>
     */
    protected function serializeAnnouncements(Collection $items, User $viewer, PipelineBoard $board): array
    {
        $readStates = PipelineBoardAnnouncementRead::query()
            ->where('user_id', $viewer->id)
            ->whereIn('announcement_id', $items->pluck('id'))
            ->pluck('is_read', 'announcement_id')
            ->all();

        $teamSize = count($this->boardTeamMembers($board, $viewer));

        return $items->map(function (PipelineBoardAnnouncement $item) use ($viewer, $board, $readStates, $teamSize) {
            return $this->serializeAnnouncement($item, $viewer, $board, $readStates, $teamSize);
        })->all();
    }

    /** @param  array<int, bool>|null  $readStates
     * @return array<string, mixed>
     */
    protected function serializeAnnouncement(
        PipelineBoardAnnouncement $item,
        User $viewer,
        PipelineBoard $board,
        ?array $readStates = null,
        ?int $teamSize = null,
    ): array {
        $isCreator = (int) $item->created_by === (int) $viewer->id;
        $canManage = $isCreator || $this->pipeline->userCanManageBoard($viewer, $board);

        $readCount = null;
        $teamMemberCount = null;
        if ($canManage) {
            $readCount = PipelineBoardAnnouncementRead::query()
                ->where('announcement_id', $item->id)
                ->where('is_read', true)
                ->count();
            $teamMemberCount = $teamSize ?? count($this->boardTeamMembers($board, $viewer));
        }

        return [
            'id' => $item->id,
            'board_id' => $item->board_id,
            'title' => $item->title,
            'body' => $item->body,
            'is_pinned' => $item->is_pinned,
            'created_by' => $item->created_by,
            'creator' => $item->creator,
            'created_at' => $item->created_at?->toIso8601String(),
            'is_read' => (bool) ($readStates[(int) $item->id] ?? false),
            'read_count' => $readCount,
            'team_member_count' => $teamMemberCount,
            'can_delete' => $canManage,
            'can_dismiss' => ! $canManage,
        ];
    }

    /** @param  Collection<int, PipelinePoll>  $polls
     * @return list<array<string, mixed>>
     */
    protected function serializePolls(Collection $polls, User $viewer, PipelineBoard $board): array
    {
        return $polls->map(fn (PipelinePoll $poll) => $this->serializePoll($poll, $viewer, $board))->all();
    }

    /** @return array<string, mixed> */
    protected function serializePoll(PipelinePoll $poll, User $viewer, PipelineBoard $board): array
    {
        $isCreator = (int) $poll->created_by === (int) $viewer->id;
        $canManagePoll = $this->canManagePoll($poll, $viewer, $board);
        $visibility = $poll->results_visibility ?? 'team';
        $canSeeResults = $visibility === 'team' || $isCreator || $canManagePoll;
        $userVotes = $poll->votes->where('user_id', $viewer->id)->values();
        $userHasVoted = $userVotes->isNotEmpty();
        $totalVotes = $poll->votes->count();

        $options = $poll->options->map(function (PipelinePollOption $option) use ($poll, $canSeeResults) {
            $count = $poll->votes->where('option_id', $option->id)->count();

            return [
                'id' => $option->id,
                'poll_id' => $option->poll_id,
                'label' => $option->label,
                'sort_order' => $option->sort_order,
                'votes_count' => $canSeeResults ? $count : null,
            ];
        })->values();

        if ($canSeeResults) {
            $options = $options->sortByDesc('votes_count')->values();
        }

        $options = $options->all();

        $votes = $canSeeResults
            ? $poll->votes->map(fn ($vote) => [
                'id' => $vote->id,
                'poll_id' => $vote->poll_id,
                'option_id' => $vote->option_id,
                'user_id' => $vote->user_id,
            ])->values()->all()
            : $userVotes->map(fn ($vote) => [
                'id' => $vote->id,
                'poll_id' => $vote->poll_id,
                'option_id' => $vote->option_id,
                'user_id' => $vote->user_id,
            ])->values()->all();

        $payload = [
            'id' => $poll->id,
            'board_id' => $poll->board_id,
            'lead_id' => $poll->lead_id,
            'question' => $poll->question,
            'closes_at' => $poll->closes_at?->toIso8601String(),
            'allow_multiple' => (bool) $poll->allow_multiple,
            'results_visibility' => $visibility,
            'created_by' => $poll->created_by,
            'creator' => $poll->creator,
            'options' => $options,
            'votes' => $votes,
            'created_at' => $poll->created_at?->toIso8601String(),
            'total_votes' => $canSeeResults ? $totalVotes : null,
            'user_has_voted' => $userHasVoted,
            'can_see_results' => $canSeeResults,
            'results_hidden' => ! $canSeeResults,
            'can_manage_poll' => $canManagePoll,
            'can_remove_own_vote' => $userHasVoted,
            'can_delete' => $canManagePoll,
            'can_dismiss' => ! $canManagePoll,
        ];

        if ($canManagePoll) {
            $payload['participants'] = $this->pollParticipantStatus($poll, $board, $viewer);
        }

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    protected function pollParticipantStatus(PipelinePoll $poll, PipelineBoard $board, User $viewer): array
    {
        $members = $this->boardTeamMembers($board, $viewer);
        $votesByUser = $poll->votes->keyBy('user_id');
        $optionsById = $poll->options->keyBy('id');

        return collect($members)->map(function (User $member) use ($votesByUser, $optionsById) {
            $vote = $votesByUser->get($member->id);
            $option = $vote ? $optionsById->get($vote->option_id) : null;

            return [
                'user' => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'avatar' => $member->avatar,
                ],
                'has_voted' => $vote !== null,
                'voted_option_id' => $vote?->option_id,
                'voted_option_label' => $option?->label,
            ];
        })->values()->all();
    }

    /** @return list<User> */
    protected function boardTeamMembers(PipelineBoard $board, User $include): array
    {
        $ids = collect([(int) $board->created_by, (int) $include->id]);

        if ($board->visibility === 'team') {
            $ids = $ids->merge(
                User::query()
                    ->where('business_id', $board->business_id)
                    ->where('is_active', true)
                    ->pluck('id'),
            );
        } elseif ($board->visibility === 'shared') {
            $ids = $ids->merge($board->members()->pluck('user_id'));
        }

        return User::query()
            ->whereIn('id', $ids->unique()->values())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'avatar'])
            ->all();
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
        $lead = $this->pipeline->getLead($businessId, $user, $leadId);
        $this->pipeline->ensureCanEditBoard($user, $lead->board);
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
        $lead = $this->pipeline->getLead($businessId, $user, $leadId);

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

        $this->pipeline->getLead($businessId, $user, (int) $reminder->lead_id);

        if ((int) $reminder->created_by !== (int) $user->id && ! $this->pipeline->userCanManageBoard($user, $reminder->lead->board)) {
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
            return $this->boardRecipients($board, $actor);
        }

        return User::query()->whereIn('id', $ids)->get()->all();
    }

    /** @return list<User> */
    public function boardRecipientsForNotifications(PipelineBoard $board, User $exclude): array
    {
        return $this->boardRecipients($board, $exclude);
    }

    /** @return list<User> */
    protected function boardRecipients(PipelineBoard $board, User $exclude): array
    {
        $ids = collect([(int) $board->created_by]);

        if ($board->visibility === 'team') {
            $ids = $ids->merge(
                User::query()
                    ->where('business_id', $board->business_id)
                    ->where('is_active', true)
                    ->pluck('id'),
            );
        } elseif ($board->visibility === 'shared') {
            $ids = $ids->merge(
                $board->members()->pluck('user_id'),
            );
        }

        return User::query()
            ->whereIn('id', $ids->unique()->reject(fn ($id) => (int) $id === (int) $exclude->id)->values())
            ->where('is_active', true)
            ->get()
            ->all();
    }

    protected function assertCanManageBoard(User $user, PipelineBoard $board): void
    {
        if (! $this->pipeline->userCanManageBoard($user, $board)) {
            abort(403, 'You do not have permission to manage this board.');
        }
    }
}
