<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipelineBoard;
use App\Models\PipelineLead;
use App\Models\PipelineStage;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Http\Request;

class ProjectAccessService
{
    public function __construct(
        protected ModuleAccessService $moduleAccess,
    ) {}

    public function canViewAllProjects(User $user): bool
    {
        return $this->moduleAccess->isBusinessOwner($user)
            || $this->moduleAccess->canAccess($user, 'estimates');
    }

    public function canViewProjectCosting(User $user): bool
    {
        return $this->canViewAllProjects($user);
    }

    /** @return list<int> */
    public function memberProjectIds(User $user): array
    {
        if (!$user->business_id) {
            return [];
        }

        return ProjectMember::query()
            ->whereHas('project', fn ($q) => $q->where('business_id', $user->business_id))
            ->where('user_id', $user->id)
            ->pluck('project_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function isProjectMember(User $user, int $projectId, ?string $minRole = null): bool
    {
        $query = ProjectMember::query()
            ->where('project_id', $projectId)
            ->where('user_id', $user->id);

        if ($minRole === 'contributor') {
            $query->whereIn('role', ['contributor', 'manager']);
        } elseif ($minRole === 'manager') {
            $query->where('role', 'manager');
        }

        return $query->exists();
    }

    public function canAccessProject(User $user, Project $project): bool
    {
        if ((int) $project->business_id !== (int) $user->business_id) {
            return false;
        }

        if ($this->canViewAllProjects($user)) {
            return true;
        }

        return $this->isProjectMember($user, (int) $project->id);
    }

    public function canEditProjectBoard(User $user, Project $project): bool
    {
        if ($this->canViewAllProjects($user)) {
            return true;
        }

        return $this->isProjectMember($user, (int) $project->id, 'contributor');
    }

    public function canManageProjectMembers(User $user, Project $project): bool
    {
        if ($this->canViewAllProjects($user)) {
            return true;
        }

        return $this->isProjectMember($user, (int) $project->id, 'manager');
    }

    public function assertCanAccessProject(User $user, Project $project): void
    {
        if (!$this->canAccessProject($user, $project)) {
            abort(403, 'You do not have access to this project.');
        }
    }

    public function assertCanViewProjectCosting(User $user): void
    {
        if (!$this->canViewProjectCosting($user)) {
            abort(403, 'You do not have permission to view project costing.');
        }
    }

    public function assertCanManageProjectMembers(User $user, Project $project): void
    {
        if (!$this->canManageProjectMembers($user, $project)) {
            abort(403, 'You cannot manage members on this project.');
        }
    }

    public function canAccessProjectBoard(User $user, PipelineBoard $board): bool
    {
        if (!$board->project_id) {
            return false;
        }

        $project = Project::query()->find($board->project_id);
        if (!$project) {
            return false;
        }

        return $this->canAccessProject($user, $project);
    }

    public function canAccessPipelineRoute(User $user, Request $request): bool
    {
        if ($this->moduleAccess->canAccess($user, 'pipeline')) {
            return true;
        }

        $board = $this->resolveBoardFromRequest($request, (int) $user->business_id);
        if (!$board || !$board->project_id) {
            return false;
        }

        return $this->canAccessProjectBoard($user, $board);
    }

    protected function resolveBoardFromRequest(Request $request, int $businessId): ?PipelineBoard
    {
        $boardId = $request->route('id') ?? $request->route('boardId');
        if ($boardId && is_numeric($boardId)) {
            return PipelineBoard::query()
                ->where('business_id', $businessId)
                ->whereKey((int) $boardId)
                ->first();
        }

        $leadId = $request->route('leadId') ?? $request->route('id');
        if ($leadId && is_numeric($leadId) && str_contains($request->path(), 'pipeline/leads')) {
            $lead = PipelineLead::query()
                ->where('business_id', $businessId)
                ->whereKey((int) $leadId)
                ->with('board')
                ->first();

            return $lead?->board;
        }

        $stageId = $request->route('stageId');
        if ($stageId && is_numeric($stageId)) {
            $stage = PipelineStage::query()
                ->where('business_id', $businessId)
                ->whereKey((int) $stageId)
                ->with('board')
                ->first();

            return $stage?->board;
        }

        return null;
    }
}
