<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelinePoll;
use App\Models\PipelinePollDismissal;
use App\Models\PipelinePollOption;
use App\Models\PipelinePollVote;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PipelineBoardPollService
{
    public function __construct(
        protected PipelineBoardService $boards,
        protected PipelineBoardPermissionService $permission,
        protected PipelineLeadService $leads,
        protected PipelineNotificationService $notifier,
    ) {}

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
        $board = $this->boards->getBoard($businessId, $user, $boardId);
        $this->permission->assertCanManageBoard($user, $board);

        if ($leadId) {
            $this->leads->getLead($businessId, $user, $leadId);
        }

        if (! in_array($resultsVisibility, ['team', 'creator_only'], true)) {
            abort(422, 'Results visibility must be team or creator_only.');
        }

        if ($closesAt && Carbon::parse($closesAt)->isPast()) {
            abort(422, 'Voting deadline must be in the future.');
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
        $recipients = $this->notifier->boardRecipientsForNotifications($board, $user);
        $this->notifier->notifyPoll($poll, $board, $user, $recipients);

        return $this->serializePoll($poll, $user, $board);
    }

    /** @return list<array<string, mixed>> */
    public function listPolls(int $businessId, User $user, int $boardId, ?int $leadId = null): array
    {
        $board = $this->boards->getBoard($businessId, $user, $boardId);

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

    /**
     * @param  array{
     *   question?: string,
     *   options?: list<array{id?: int, label: string}>,
     *   closes_at?: string|null,
     *   results_visibility?: string,
     * }  $data
     * @return array<string, mixed>
     */
    public function updatePoll(int $businessId, User $user, int $pollId, array $data): array
    {
        $poll = PipelinePoll::query()
            ->where('business_id', $businessId)
            ->whereKey($pollId)
            ->with(['options', 'creator:id,name,avatar', 'votes'])
            ->firstOrFail();

        $board = $this->boards->getBoard($businessId, $user, (int) $poll->board_id);

        if (! $this->canEditPoll($poll, $user)) {
            abort(403, 'Only the poll creator can edit this poll.');
        }

        DB::transaction(function () use ($poll, $data) {
            if (array_key_exists('question', $data)) {
                $question = trim((string) $data['question']);
                if ($question === '') {
                    abort(422, 'Poll question is required.');
                }
                $poll->question = $question;
            }

            if (array_key_exists('closes_at', $data)) {
                $poll->closes_at = $data['closes_at']
                    ? Carbon::parse((string) $data['closes_at'])
                    : null;
            }

            if (array_key_exists('results_visibility', $data)) {
                $visibility = (string) $data['results_visibility'];
                if (! in_array($visibility, ['team', 'creator_only'], true)) {
                    abort(422, 'Results visibility must be team or creator_only.');
                }
                $poll->results_visibility = $visibility;
            }

            $poll->save();

            if (array_key_exists('options', $data)) {
                $this->syncPollOptions($poll, $data['options'] ?? []);
            }
        });

        return $this->serializePoll(
            $poll->fresh(['options', 'creator:id,name,avatar', 'votes']),
            $user,
            $board,
        );
    }

    /** @return array<string, mixed> */
    public function votePoll(int $businessId, User $user, int $pollId, int $optionId): array
    {
        $poll = PipelinePoll::query()
            ->where('business_id', $businessId)
            ->whereKey($pollId)
            ->with('options')
            ->firstOrFail();

        $board = $this->boards->getBoard($businessId, $user, (int) $poll->board_id);

        $this->permission->ensureCanContributeToBoard($user, $board);

        if ($this->pollIsClosed($poll)) {
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

        $board = $this->boards->getBoard($businessId, $user, (int) $poll->board_id);
        $targetUserId = $targetUserId ?? $user->id;

        if ((int) $targetUserId !== (int) $user->id) {
            abort(403, 'You can only remove your own vote.');
        }

        $this->permission->ensureCanContributeToBoard($user, $board);

        if ($this->pollIsClosed($poll)) {
            abort(422, 'This poll is closed.');
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

        $board = $this->boards->getBoard($businessId, $user, (int) $poll->board_id);

        if ($this->canManagePoll($poll, $user, $board)) {
            $poll->delete();

            return;
        }

        $this->permission->ensureCanContributeToBoard($user, $board);
        $this->dismissPollForUser($poll, $user);
    }

    /** @return list<int> */
    public function dismissedPollIdsForUser(int $userId, int $boardId): array
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

    public function pollIsClosed(PipelinePoll $poll): bool
    {
        return $poll->closes_at && $poll->closes_at->isPast();
    }

    protected function canManagePoll(PipelinePoll $poll, User $user, PipelineBoard $board): bool
    {
        return (int) $poll->created_by === (int) $user->id
            || $this->permission->userCanManageBoard($user, $board);
    }

    protected function canEditPoll(PipelinePoll $poll, User $user): bool
    {
        return (int) $poll->created_by === (int) $user->id;
    }

    /** @param  list<array{id?: int, label: string}>  $options */
    protected function syncPollOptions(PipelinePoll $poll, array $options): void
    {
        $votesByOption = $poll->votes->groupBy('option_id');
        $existingIds = $poll->options->pluck('id')->map(fn ($id) => (int) $id)->all();
        $keptIds = [];

        foreach (array_values($options) as $index => $optionData) {
            $label = trim((string) ($optionData['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $id = isset($optionData['id']) ? (int) $optionData['id'] : null;
            if ($id && in_array($id, $existingIds, true)) {
                PipelinePollOption::query()
                    ->where('poll_id', $poll->id)
                    ->whereKey($id)
                    ->update(['label' => $label, 'sort_order' => $index]);
                $keptIds[] = $id;
            } else {
                $created = PipelinePollOption::create([
                    'poll_id' => $poll->id,
                    'label' => $label,
                    'sort_order' => $index,
                ]);
                $keptIds[] = (int) $created->id;
            }
        }

        if (count($keptIds) < 2) {
            abort(422, 'A poll must have at least two options.');
        }

        foreach ($existingIds as $existingId) {
            if (in_array($existingId, $keptIds, true)) {
                continue;
            }

            if ($votesByOption->has($existingId)) {
                abort(422, 'Cannot remove an option that already has votes.');
            }

            PipelinePollOption::query()
                ->where('poll_id', $poll->id)
                ->whereKey($existingId)
                ->delete();
        }
    }

    protected function dismissPollForUser(PipelinePoll $poll, User $user): void
    {
        PipelinePollDismissal::updateOrCreate(
            ['poll_id' => $poll->id, 'user_id' => $user->id],
            ['dismissed_at' => now()],
        );
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
        $canEditPoll = $this->canEditPoll($poll, $viewer);
        $canContribute = $this->permission->userCanContributeToBoard($viewer, $board);
        $isClosed = $this->pollIsClosed($poll);
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
            'can_edit_poll' => $canEditPoll,
            'is_closed' => $isClosed,
            'can_remove_own_vote' => $userHasVoted && $canContribute && ! $isClosed,
            'can_vote' => $canContribute && ! $isClosed,
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
        $members = $this->permission->boardTeamMembers($board, $viewer);
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
}
