<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineLead;
use App\Models\PipelineLeadActivity;
use App\Models\PipelineLeadLink;
use App\Models\User;
use App\Services\Pipeline\PipelineBoardLookupService;
use App\Services\Pipeline\PipelineBoardPermissionService;
use App\Services\Pipeline\PipelineCollaborationService;
use App\Services\Pipeline\PipelineNotificationService;

class PipelineActivityService
{
    public function __construct(
        protected PipelineBoardLookupService $lookup,
        protected PipelineBoardPermissionService $permission,
        protected PipelineCollaborationService $collaboration,
        protected PipelineNotificationService $pipelineNotifier,
    ) {}

    public function addActivity(
        int $businessId,
        User $user,
        int $leadId,
        string $type,
        ?string $body,
        ?array $metadata = null,
        ?int $parentId = null,
    ): PipelineLeadActivity {
        $lead = $this->lookup->findLeadForUser($user, $leadId);
        $this->permission->assertCanEditBoard($user, $lead->board);

        $effectiveBusinessId = (int) $lead->business_id;
        if ($parentId !== null) {
            $parent = PipelineLeadActivity::query()
                ->where('business_id', $effectiveBusinessId)
                ->where('lead_id', $leadId)
                ->whereKey($parentId)
                ->firstOrFail();

            if (! in_array($parent->type, ['note', 'comment', 'call', 'email', 'meeting'], true)) {
                abort(422, 'You can only reply to user comments.');
            }

            if ($parent->parent_id !== null) {
                abort(422, 'Replies cannot be nested further - reply to the main comment instead.');
            }
        }

        return PipelineLeadActivity::create([
            'business_id' => $effectiveBusinessId,
            'lead_id' => $leadId,
            'parent_id' => $parentId,
            'user_id' => $user->id,
            'type' => $type,
            'body' => $body,
            'metadata' => $metadata,
        ]);
    }

    public function addActivityAndNotify(
        int $businessId,
        User $user,
        int $leadId,
        string $type,
        ?string $body,
        ?array $metadata = null,
        ?int $parentId = null,
    ): PipelineLeadActivity {
        $activity = $this->addActivity($businessId, $user, $leadId, $type, $body, $metadata, $parentId);

        if (in_array($type, ['note', 'comment', 'call', 'email', 'meeting'], true) && $body) {
            $lead = $this->lookup->findLeadForUser($user, $leadId);
            $lead->load('board');
            $recipients = $this->collaboration->leadNotificationRecipients($lead, $user);
            $this->pipelineNotifier->notifyComment(
                $lead,
                $lead->board,
                $user,
                $body,
                $recipients,
                $parentId !== null,
            );
        }

        return $activity;
    }

    public function deleteActivity(int $businessId, User $user, int $activityId): void
    {
        $activity = PipelineLeadActivity::query()
            ->whereKey($activityId)
            ->firstOrFail();

        if (! in_array($activity->type, ['note', 'comment', 'call', 'email', 'meeting'], true)) {
            abort(403, 'This activity cannot be deleted.');
        }

        $lead = $this->lookup->findLeadForUser($user, (int) $activity->lead_id);
        $board = $lead->board ?? $this->lookup->findBoardForUser($user, (int) $lead->board_id);

        $isAuthor = (int) $activity->user_id === (int) $user->id;
        $canModerate = $this->permission->userCanManageBoard($user, $board);

        if (! $isAuthor && ! $canModerate) {
            abort(403, 'You can only delete your own comments or moderate as a board manager.');
        }

        if (in_array($activity->type, ['note', 'comment', 'call', 'email', 'meeting'], true)) {
            $preview = $activity->body ? mb_substr($activity->body, 0, 120) : null;
            $lead->activities()->create([
                'business_id' => $lead->business_id,
                'user_id' => $user->id,
                'type' => 'system',
                'body' => 'Comment removed',
                'metadata' => [
                    'action' => 'comment_removed',
                    'comment_type' => $activity->type,
                    'preview' => $preview,
                ],
            ]);
        }

        PipelineLeadActivity::query()
            ->where('lead_id', $lead->id)
            ->where('parent_id', $activity->id)
            ->delete();

        $activity->delete();
    }

    public function updateActivity(int $businessId, User $user, int $activityId, string $body): PipelineLeadActivity
    {
        $activity = PipelineLeadActivity::query()
            ->whereKey($activityId)
            ->firstOrFail();

        if (! in_array($activity->type, ['note', 'comment', 'call', 'email', 'meeting'], true)) {
            abort(403, 'This activity cannot be edited.');
        }

        $lead = $this->lookup->findLeadForUser($user, (int) $activity->lead_id);
        $board = $lead->board ?? $this->lookup->findBoardForUser($user, (int) $lead->board_id);

        $isAuthor = (int) $activity->user_id === (int) $user->id;

        if (! $isAuthor) {
            abort(403, 'You can only edit your own comments.');
        }

        $beforeBody = $activity->body;
        $activity->update(['body' => $body]);

        if ($beforeBody !== $body) {
            $lead->activities()->create([
                'business_id' => $lead->business_id,
                'user_id' => $user->id,
                'type' => 'system',
                'body' => 'Comment edited',
                'metadata' => [
                    'action' => 'comment_edited',
                    'comment_type' => $activity->type,
                    'preview' => mb_substr($body, 0, 120),
                ],
            ]);
        }

        return $activity->fresh(['user:id,name,avatar', 'reactions']);
    }

    public function logLeadHistoryEvent(
        PipelineLead $lead,
        User $user,
        string $body,
        ?array $metadata = null,
    ): PipelineLeadActivity {
        return $lead->activities()->create([
            'business_id' => $lead->business_id,
            'user_id' => $user->id,
            'type' => 'system',
            'body' => $body,
            'metadata' => $metadata,
        ]);
    }
}
