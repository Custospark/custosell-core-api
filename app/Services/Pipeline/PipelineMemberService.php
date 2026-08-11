<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardMember;
use App\Models\User;
use App\Services\ModuleAccessService;
use App\Services\Pipeline\PipelineNotificationService;
use Illuminate\Validation\ValidationException;

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

        $requested = collect($members)
            ->map(fn ($entry) => is_array($entry) ? (int) ($entry['user_id'] ?? 0) : (int) $entry)
            ->filter(fn (int $userId) => $userId > 0 && $userId !== (int) $board->created_by)
            ->unique()
            ->values();

        $invitees = collect();
        if ($requested->isNotEmpty()) {
            $invitees = User::query()
                ->with(['business.subscription'])
                ->whereKey($requested->all())
                ->get()
                ->keyBy('id');

            $ineligible = [];
            foreach ($requested as $userId) {
                $user = $invitees->get($userId);
                if (! $user || ! $user->is_active || ! $this->userCanAccessBoard($user, $board)) {
                    $ineligible[] = $user?->email ?? "#{$userId}";
                }
            }

            if ($ineligible !== []) {
                throw ValidationException::withMessages([
                    'members' => 'These users cannot access this board (out of pipeline/estimates scope or no active subscription): '
                        .implode(', ', $ineligible)
                        .'.',
                ]);
            }
        }

        PipelineBoardMember::query()->where('board_id', $board->id)->delete();

        foreach ($members as $entry) {
            $userId = is_array($entry) ? (int) ($entry['user_id'] ?? 0) : (int) $entry;
            $role = is_array($entry) ? ($entry['role'] ?? 'contributor') : 'contributor';
            $sendNotification = is_array($entry) && ($entry['send_notification'] ?? false);

            if ($userId === 0 || $userId === (int) $board->created_by) {
                continue;
            }

            $role = match ($role) {
                'viewer', 'contributor', 'manager' => $role,
                'editor' => 'contributor',
                default => 'viewer',
            };
            PipelineBoardMember::create([
                'board_id' => $board->id,
                'user_id' => $userId,
                'role' => $role,
            ]);

            if ($sendNotification && $actorUserId && ! in_array($userId, $existingUserIds, true)) {
                $recipient = $invitees?->get($userId) ?? User::find($userId);
                if ($recipient) {
                    $actor = User::find($actorUserId);
                    if ($actor) {
                        $this->pipelineNotifier->notifyBoardMemberAdded($board, $actor, [$recipient], $role);
                    }
                }
            }
        }
    }

    /**
     * Mirrors the board-access rule used when opening a board (ProjectAccessService),
     * so invited users can actually collaborate:
     *  - same-business invitees need an active subscription;
     *  - cross-business/external invitees need pipeline or estimates module access.
     */
    protected function userCanAccessBoard(User $user, PipelineBoard $board): bool
    {
        if ((int) $user->business_id === (int) $board->business_id) {
            return (bool) ($user->business?->subscription?->hasAccess() ?? false);
        }

        return $this->moduleAccess->canAccess($user, 'pipeline')
            || $this->moduleAccess->canAccess($user, 'estimates');
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

        return $this->moduleAccess->canAccess($user, 'pipeline');
    }
}
