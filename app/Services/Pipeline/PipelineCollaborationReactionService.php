<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineActivityReaction;
use App\Models\PipelineLead;
use App\Models\PipelineLeadActivity;
use App\Models\PipelineLeadAssignee;
use App\Models\User;

class PipelineCollaborationReactionService
{
    public function __construct(
        protected PipelineLeadService $leads,
        protected PipelineBoardPermissionService $permission,
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

        $lead = $this->leads->getLead($businessId, $user, (int) $activity->lead_id);
        $this->permission->ensureCanContributeToBoard($user, $lead->board);

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
            $this->leads->logLeadHistoryEvent($lead, $user, $label, [
                'action' => 'reaction',
                'reaction' => $currentReaction,
                'target_activity_id' => $activity->id,
                'preview' => $preview,
            ]);
        } elseif (! $currentReaction && $previousReaction) {
            $this->leads->logLeadHistoryEvent($lead, $user, 'Removed reaction', [
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
}
