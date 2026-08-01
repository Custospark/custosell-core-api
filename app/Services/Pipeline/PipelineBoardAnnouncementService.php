<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardAnnouncement;
use App\Models\PipelineBoardAnnouncementRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class PipelineBoardAnnouncementService
{
    public function __construct(
        protected PipelineBoardService $boards,
        protected PipelineBoardPermissionService $permission,
        protected PipelineNotificationService $notifier,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listAnnouncements(int $businessId, User $user, int $boardId): array
    {
        $board = $this->boards->getBoard($businessId, $user, $boardId);

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
        $board = $this->boards->getBoard($businessId, $user, $boardId);
        $this->permission->assertCanManageBoard($user, $board);

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

        $recipients = $this->notifier->boardRecipientsForNotifications($board, $user);
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

        $board = $this->boards->getBoard($businessId, $user, (int) $announcement->board_id);

        PipelineBoardAnnouncementRead::updateOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $user->id],
            ['is_read' => $isRead, 'read_at' => $isRead ? now() : null],
        );

        return $this->serializeAnnouncement($announcement, $user, $board);
    }

    public function deleteAnnouncement(int $businessId, User $user, int $announcementId): void
    {
        $announcement = PipelineBoardAnnouncement::query()
            ->where('business_id', $businessId)
            ->whereKey($announcementId)
            ->firstOrFail();

        $board = $this->boards->getBoard($businessId, $user, (int) $announcement->board_id);
        $this->permission->ensureCanContributeToBoard($user, $board);

        $canDeleteForAll = (int) $announcement->created_by === (int) $user->id
            || $this->permission->userCanManageBoard($user, $board);

        if ($canDeleteForAll) {
            $announcement->delete();

            return;
        }

        $this->dismissAnnouncementForUser($announcement, $user);
    }

    /** @return list<int> */
    public function dismissedAnnouncementIdsForUser(int $userId, int $boardId): array
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

        $teamSize = count($this->permission->boardTeamMembers($board, $viewer));

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
        $canManage = $isCreator || $this->permission->userCanManageBoard($viewer, $board);

        $readCount = null;
        $teamMemberCount = null;
        if ($canManage) {
            $readCount = PipelineBoardAnnouncementRead::query()
                ->where('announcement_id', $item->id)
                ->where('is_read', true)
                ->count();
            $teamMemberCount = $teamSize ?? count($this->permission->boardTeamMembers($board, $viewer));
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
}
