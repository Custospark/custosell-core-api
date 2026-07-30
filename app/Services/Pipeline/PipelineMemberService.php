<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardMember;
use App\Models\User;
use App\Services\ModuleAccessService;
use App\Services\Pipeline\PipelineNotificationService;

class PipelineMemberService
{
    public function __construct(
        protected ModuleAccessService $moduleAccess,
        protected PipelineNotificationService $pipelineNotifier,
    ) {}

    public function syncBoardMembers(PipelineBoard $board, array $members, ?int $actorUserId = null): void
    {
        $existingUserIds = PipelineBoardMember::query()
            ->where('board_id', $board->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        PipelineBoardMember::query()->where('board_id', $board->id)->delete();

        foreach ($members as $entry) {
            $userId = is_array($entry) ? (int) ($entry['user_id'] ?? 0) : (int) $entry;
            $role = is_array($entry) ? ($entry['role'] ?? 'contributor') : 'contributor';
            $sendNotification = is_array($entry) && ($entry['send_notification'] ?? false);

            if ($userId === 0 || $userId === (int) $board->created_by) {
                continue;
            }

            if (! in_array($role, ['viewer', 'contributor', 'manager'], true)) {
                $role = 'viewer';
            }
            PipelineBoardMember::create([
                'board_id' => $board->id,
                'user_id' => $userId,
                'role' => $role,
            ]);

            if ($sendNotification && $actorUserId && ! in_array($userId, $existingUserIds, true)) {
                $recipient = User::find($userId);
                if ($recipient) {
                    $actor = User::find($actorUserId);
                    if ($actor) {
                        $this->pipelineNotifier->notifyBoardMemberAdded($board, $actor, [$recipient], $role);
                    }
                }
            }
        }
    }

    public function listBoardTeamMembers(int $businessId, string $workspace = 'pipeline', string $scope = 'workspace'): array
    {
        $workspace = $workspace === 'estimates' ? 'estimates' : 'pipeline';
        $scope = $scope === 'business' ? 'business' : 'workspace';

        return User::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $scope === 'business'
                || $this->userEligibleForBoardWorkspace($user, $workspace))
            ->map(fn (User $user) => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'modules' => $this->moduleAccess->accessibleModules($user),
            ])
            ->values()
            ->all();
    }

    protected function userEligibleForBoardWorkspace(User $user, string $workspace): bool
    {
        if ($this->moduleAccess->isBusinessOwner($user)) {
            return true;
        }

        if ($workspace === 'estimates') {
            return true;
        }

        return in_array('pipeline', $this->moduleAccess->storedStaffModules($user), true);
    }
}
