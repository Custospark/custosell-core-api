<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardMember;
use App\Models\PipelineChecklist;
use App\Models\PipelineChecklistItem;
use App\Models\PipelineLead;
use App\Models\PipelineStage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;

class ProjectAccessService
{
    public function __construct(
        protected ModuleAccessService $moduleAccess,
    ) {}

    public function canViewAllProjects(User $user): bool
    {
        return $this->moduleAccess->isBusinessOwner($user);
    }

    public function canViewProjectCosting(User $user): bool
    {
        return $this->moduleAccess->isBusinessOwner($user);
    }

    /** @return list<int> */
    public function memberProjectIds(User $user): array
    {
        if (!$user->business_id) {
            return [];
        }

        return \App\Models\ProjectMember::query()
            ->whereHas('project', fn ($q) => $q->where('business_id', $user->business_id))
            ->where('user_id', $user->id)
            ->pluck('project_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function isProjectMember(User $user, int $projectId, ?string $minRole = null): bool
    {
        $query = \App\Models\ProjectMember::query()
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

        if ((int) $project->created_by === (int) $user->id) {
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

        $businessId = (int) $user->business_id;
        $path = $request->path();

        if ($this->moduleAccess->canAccess($user, 'estimates')) {
            $board = $this->resolveBoardFromRequest($request, $businessId);
            if ($board) {
                if ($board->project_id) {
                    return $this->canAccessProjectBoard($user, $board);
                }

                return $this->canAccessNonProjectBoard($user, $board);
            }

            if (str_contains($path, 'pipeline/boards')) {
                return true;
            }

            if (str_contains($path, 'pipeline/stages')
                || str_contains($path, 'pipeline/leads')
                || str_contains($path, 'pipeline/checklists')
                || str_contains($path, 'pipeline/checklist-items')
                || str_contains($path, 'pipeline/labels')) {
                $board = $this->resolveBoardFromRequest($request, $businessId);
                if ($board) {
                    return $board->project_id
                        ? $this->canAccessProjectBoard($user, $board)
                        : $this->canAccessNonProjectBoard($user, $board);
                }
            }
        }

        $board = $this->resolveBoardFromRequest($request, $businessId);
        if (!$board) {
            return false;
        }

        if (!$board->project_id) {
            return $this->canAccessNonProjectBoard($user, $board);
        }

        return $this->canAccessProjectBoard($user, $board);
    }

    protected function canAccessNonProjectBoard(User $user, PipelineBoard $board): bool
    {
        if ((int) $board->business_id !== (int) $user->business_id) {
            return false;
        }

        if ((int) $board->created_by === (int) $user->id) {
            return true;
        }

        if ($board->visibility === 'shared') {
            return PipelineBoardMember::query()
                ->where('board_id', $board->id)
                ->where('user_id', $user->id)
                ->exists();
        }

        return false;
    }

    protected function resolveBoardFromRequest(Request $request, int $businessId): ?PipelineBoard
    {
        $boardId = $request->route('id') ?? $request->route('boardId');
        if ($boardId && is_numeric($boardId)) {
            $path = $request->path();
            if (str_contains($path, 'pipeline/boards') || str_contains($path, 'pipeline/stages')) {
                return $this->findBoard($businessId, (int) $boardId);
            }
        }

        $bodyBoardId = $request->input('board_id');
        if ($bodyBoardId && is_numeric($bodyBoardId)) {
            return $this->findBoard($businessId, (int) $bodyBoardId);
        }

        $routeLeadId = $request->route('leadId');
        if ($routeLeadId && is_numeric($routeLeadId)) {
            return $this->boardFromLeadId($businessId, (int) $routeLeadId);
        }

        $leadId = $request->route('id');
        if ($leadId && is_numeric($leadId) && str_contains($request->path(), 'pipeline/leads')) {
            return $this->boardFromLeadId($businessId, (int) $leadId);
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

        $checklistId = $request->route('checklistId');
        if ($checklistId && is_numeric($checklistId)) {
            $checklist = PipelineChecklist::query()
                ->where('business_id', $businessId)
                ->whereKey((int) $checklistId)
                ->with('lead.board')
                ->first();

            return $checklist?->lead?->board;
        }

        $checklistItemId = $request->route('id');
        if ($checklistItemId && is_numeric($checklistItemId) && str_contains($request->path(), 'pipeline/checklist-items')) {
            $item = PipelineChecklistItem::query()
                ->whereKey((int) $checklistItemId)
                ->with(['checklist.lead.board'])
                ->first();

            return $item?->checklist?->lead?->board;
        }

        $checklistRouteId = $request->route('id');
        if ($checklistRouteId && is_numeric($checklistRouteId) && str_contains($request->path(), 'pipeline/checklists')) {
            $checklist = PipelineChecklist::query()
                ->where('business_id', $businessId)
                ->whereKey((int) $checklistRouteId)
                ->with('lead.board')
                ->first();

            return $checklist?->lead?->board;
        }

        return null;
    }

    protected function findBoard(int $businessId, int $boardId): ?PipelineBoard
    {
        return PipelineBoard::query()
            ->where('business_id', $businessId)
            ->whereKey($boardId)
            ->first();
    }

    protected function boardFromLeadId(int $businessId, int $leadId): ?PipelineBoard
    {
        $lead = PipelineLead::query()
            ->where('business_id', $businessId)
            ->whereKey($leadId)
            ->with('board')
            ->first();

        return $lead?->board;
    }
}
