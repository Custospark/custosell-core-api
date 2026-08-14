<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardMember;
use App\Models\Project;
use App\Models\User;
use App\Services\ModuleAccessService;
use App\Services\ProjectAccessService;

class PipelineBoardPermissionService
{
    public function __construct(
        protected ModuleAccessService $moduleAccess,
        protected ProjectAccessService $projectAccess,
    ) {}

    public function canViewBoard(User $user, PipelineBoard $board): bool
    {
        if ($board->visibility === 'private' && ! $board->project_id) {
            return (int) $board->created_by === (int) $user->id;
        }

        if ($this->ownsBoardBusiness($user, $board)) {
            return true;
        }

        if ($board->project_id) {
            return $this->projectAccess->canAccessProjectBoard($user, $board);
        }

        return match ($board->visibility) {
            'team' => $this->moduleAccess->canAccess($user, 'pipeline')
                || $this->moduleAccess->canAccess($user, 'estimates'),
            'private' => (int) $board->created_by === (int) $user->id,
            'shared' => (int) $board->created_by === (int) $user->id
                || ($this->userIsBoardMember($user, $board)
                    && $this->externalContributorHasAccess($user, $board)),
            default => false,
        };
    }

    /**
     * Whether the user owns the business the board belongs to. Business owners
     * enjoy full board rights only within their own business - an external
     * contributor who owns a different business must NOT inherit owner powers
     * (or the "Manager" role) on this board.
     */
    protected function ownsBoardBusiness(User $user, PipelineBoard $board): bool
    {
        return (int) $board->business_id === (int) $user->business_id
            && $this->moduleAccess->isBusinessOwner($user);
    }

    /**
     * External contributors (users added to a board owned by a different
     * business) may only access the board when they hold pipeline or estimates
     * module access in their own account AND have an active subscription or
     * trial. Same-business members are never subject to this gate.
     */
    public function externalContributorHasAccess(User $user, PipelineBoard $board): bool
    {
        if ((int) $board->business_id === (int) $user->business_id) {
            return true;
        }

        if (! $user->business_id) {
            return false;
        }

        if (! $this->moduleAccess->canAccess($user, 'pipeline')
            && ! $this->moduleAccess->canAccess($user, 'estimates')) {
            return false;
        }

        $subscription = $user->business?->subscription;

        return $subscription ? $subscription->hasAccess() : false;
    }

    protected function userIsBoardMember(User $user, PipelineBoard $board): bool
    {
        return PipelineBoardMember::query()
            ->where('board_id', $board->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    public function assertCanViewBoard(User $user, PipelineBoard $board): void
    {
        if (!$this->canViewBoard($user, $board)) {
            abort(403, 'You do not have access to this pipeline board.');
        }
    }

    public function userCanManageBoard(User $user, PipelineBoard $board): bool
    {
        if ($board->visibility === 'private' && ! $board->project_id) {
            return (int) $board->created_by === (int) $user->id;
        }

        if ($this->ownsBoardBusiness($user, $board)) {
            return true;
        }

        if ($board->project_id) {
            $project = Project::query()->find($board->project_id);

            return $project && $this->projectAccess->canManageProjectMembers($user, $project);
        }

        if ((int) $board->created_by === (int) $user->id) {
            return true;
        }

        if ($board->visibility === 'shared') {
            $member = PipelineBoardMember::query()
                ->where('board_id', $board->id)
                ->where('user_id', $user->id)
                ->first();

            return $member && $this->boardMemberRoleAllowsManage($member->role);
        }

        return false;
    }

    public function assertCanEditBoard(User $user, PipelineBoard $board): void
    {
        $this->assertCanViewBoard($user, $board);

        if ($this->ownsBoardBusiness($user, $board) || (int) $board->created_by === (int) $user->id) {
            return;
        }

        if ($board->project_id) {
            $project = Project::query()->find($board->project_id);
            if ($project && $this->projectAccess->canEditProjectBoard($user, $project)) {
                return;
            }
            abort(403, 'You cannot edit this project board.');
        }

        if ($board->visibility === 'team') {
            if (! $this->userCanContributeToBoard($user, $board)) {
                abort(403, 'You have read-only access to this board.');
            }
            return;
        }

        if ($board->visibility === 'shared') {
            $member = PipelineBoardMember::query()
                ->where('board_id', $board->id)
                ->where('user_id', $user->id)
                ->first();

            if ($member && $this->boardMemberRoleAllowsEdit($member->role)) {
                return;
            }
        }

        abort(403, 'You cannot edit this pipeline board.');
    }

    public function userCanContributeToBoard(User $user, PipelineBoard $board): bool
    {
        if (! $this->canViewBoard($user, $board)) {
            return false;
        }

        if ($board->visibility === 'private' && ! $board->project_id) {
            return (int) $board->created_by === (int) $user->id;
        }

        if ($this->ownsBoardBusiness($user, $board) || (int) $board->created_by === (int) $user->id) {
            return true;
        }

        if ($board->project_id) {
            $project = Project::query()->find($board->project_id);
            return $project && $this->projectAccess->canEditProjectBoard($user, $project);
        }

        if ($board->visibility === 'team') {
            return $this->moduleAccess->canAccess($user, 'pipeline')
                || $this->moduleAccess->canAccess($user, 'estimates');
        }

        if ($board->visibility === 'shared') {
            $member = PipelineBoardMember::query()
                ->where('board_id', $board->id)
                ->where('user_id', $user->id)
                ->first();

            return $member
                && $this->boardMemberRoleAllowsEdit($member->role)
                && $this->externalContributorHasAccess($user, $board);
        }

        return false;
    }

    public function ensureCanContributeToBoard(User $user, PipelineBoard $board): void
    {
        if (! $this->userCanContributeToBoard($user, $board)) {
            abort(403, 'You have read-only access to this board.');
        }
    }

    public function ensureCanEditBoard(User $user, PipelineBoard $board): void
    {
        $this->assertCanEditBoard($user, $board);
    }

    public function ensureCanManageBoard(User $user, PipelineBoard $board): void
    {
        $this->assertCanManageBoard($user, $board);
    }

    public function assertCanManageBoard(User $user, PipelineBoard $board): void
    {
        if (! $this->userCanManageBoard($user, $board)) {
            abort(403, 'You do not have permission to manage this board.');
        }
    }

    public function assertCanArchiveBoard(User $user, PipelineBoard $board): void
    {
        if ($board->project_id) {
            $project = Project::query()->find($board->project_id);
            if ($project && ($this->projectAccess->canArchiveProjectBoard($user, $project) || $this->userCanManageBoard($user, $board))) {
                return;
            }
            abort(403, 'You do not have permission to archive this board.');
        }

        $this->assertCanManageBoard($user, $board);
    }

    public function normalizeBoardMemberRole(string $role): string
    {
        return match ($role) {
            'editor' => 'contributor',
            'viewer', 'contributor', 'manager' => $role,
            default => 'viewer',
        };
    }

    public function resolveCurrentUserBoardMemberRole(User $user, PipelineBoard $board): ?string
    {
        if ($this->ownsBoardBusiness($user, $board) || (int) $board->created_by === (int) $user->id) {
            return 'manager';
        }

        if ($board->project_id) {
            $project = Project::query()->find($board->project_id);
            if ($project && (int) $project->created_by === (int) $user->id) {
                return 'manager';
            }
            if ($project && $this->projectAccess->canEditProjectBoard($user, $project)) {
                return 'contributor';
            }
        }

        if ($board->visibility === 'team') {
            return $this->moduleAccess->canAccess($user, 'pipeline')
                || $this->moduleAccess->canAccess($user, 'estimates')
                ? 'contributor'
                : null;
        }

        if ($board->visibility === 'shared') {
            $member = PipelineBoardMember::query()
                ->where('board_id', $board->id)
                ->where('user_id', $user->id)
                ->first();

            if ($member && $this->externalContributorHasAccess($user, $board)) {
                return $this->normalizeBoardMemberRole((string) $member->role);
            }

            return null;
        }

        return null;
    }

    public function boardMemberRoleAllowsEdit(?string $role): bool
    {
        return in_array($role, ['contributor', 'manager'], true);
    }

    public function boardMemberRoleAllowsManage(?string $role): bool
    {
        return $role === 'manager';
    }

    /** @return list<User> */
    public function boardTeamMembers(PipelineBoard $board, User $include): array
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
}
