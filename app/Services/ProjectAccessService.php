<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardAnnouncement;
use App\Models\PipelineBoardAutomation;
use App\Models\PipelineBoardMember;
use App\Models\PipelineBoardMessage;
use App\Models\PipelineBoardMessageAttachment;
use App\Models\PipelineBoardResource;
use App\Models\PipelineChecklist;
use App\Models\PipelineChecklistItem;
use App\Models\PipelineLead;
use App\Models\PipelineLeadActivity;
use App\Models\PipelinePoll;
use App\Models\PipelineReminder;
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
        if ($this->moduleAccess->isBusinessOwner($user)) {
            return true;
        }

        return $this->moduleAccess->hasFullEstimatesWorkspace($user);
    }

    public function canViewProjectCosting(User $user): bool
    {
        if ($this->moduleAccess->isBusinessOwner($user)) {
            return true;
        }

        return $this->moduleAccess->hasFullEstimatesWorkspace($user);
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
        if ($this->moduleAccess->isBusinessOwner($user)) {
            return true;
        }

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
            if (str_contains($path, 'pipeline/team-members')) {
                return true;
            }

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
                || str_contains($path, 'pipeline/labels')
                || str_contains($path, 'pipeline/announcements')
                || str_contains($path, 'pipeline/polls')
                || str_contains($path, 'pipeline/activities')
                || str_contains($path, 'pipeline/reminders')
                || str_contains($path, 'collaboration-summary')) {
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
            if (str_contains($path, 'pipeline/team-members') && $this->memberProjectIds($user) !== []) {
                return true;
            }

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

        if ($board->visibility === 'private') {
            return (int) $board->created_by === (int) $user->id;
        }

        return match ($board->visibility) {
            'team' => $this->moduleAccess->isBusinessOwner($user)
                || $this->moduleAccess->canAccess($user, 'pipeline')
                || $this->moduleAccess->canAccess($user, 'estimates'),
            'shared' => $this->moduleAccess->isBusinessOwner($user)
                || (int) $board->created_by === (int) $user->id
                || PipelineBoardMember::query()
                    ->where('board_id', $board->id)
                    ->where('user_id', $user->id)
                    ->exists(),
            default => false,
        };
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
                ->whereKey((int) $checklistId)
                ->whereHas('lead', fn ($query) => $query->where('business_id', $businessId))
                ->with('lead.board')
                ->first();

            return $checklist?->lead?->board;
        }

        $checklistItemId = $request->route('id');
        if ($checklistItemId && is_numeric($checklistItemId) && str_contains($request->path(), 'pipeline/checklist-items')) {
            $item = PipelineChecklistItem::query()
                ->whereKey((int) $checklistItemId)
                ->whereHas('checklist.lead', fn ($query) => $query->where('business_id', $businessId))
                ->with(['checklist.lead.board'])
                ->first();

            return $item?->checklist?->lead?->board;
        }

        $checklistRouteId = $request->route('id');
        if ($checklistRouteId && is_numeric($checklistRouteId) && str_contains($request->path(), 'pipeline/checklists')) {
            $checklist = PipelineChecklist::query()
                ->whereKey((int) $checklistRouteId)
                ->whereHas('lead', fn ($query) => $query->where('business_id', $businessId))
                ->with('lead.board')
                ->first();

            return $checklist?->lead?->board;
        }

        $announcementId = $request->route('id');
        if ($announcementId && is_numeric($announcementId) && str_contains($request->path(), 'pipeline/announcements')) {
            $announcement = PipelineBoardAnnouncement::query()
                ->where('business_id', $businessId)
                ->whereKey((int) $announcementId)
                ->with('board')
                ->first();

            return $announcement?->board;
        }

        $pollId = $request->route('pollId');
        if ($pollId && is_numeric($pollId) && str_contains($request->path(), 'pipeline/polls')) {
            $poll = PipelinePoll::query()
                ->where('business_id', $businessId)
                ->whereKey((int) $pollId)
                ->with('board')
                ->first();

            return $poll?->board;
        }

        $activityId = $request->route('id');
        if ($activityId && is_numeric($activityId) && str_contains($request->path(), 'pipeline/activities')) {
            $activity = PipelineLeadActivity::query()
                ->where('business_id', $businessId)
                ->whereKey((int) $activityId)
                ->with('lead.board')
                ->first();

            return $activity?->lead?->board;
        }

        $resourceId = $request->route('id');
        if ($resourceId && is_numeric($resourceId) && str_contains($request->path(), 'pipeline/resources')) {
            $resource = PipelineBoardResource::query()
                ->whereKey((int) $resourceId)
                ->whereHas('board', fn ($query) => $query->where('business_id', $businessId))
                ->with('board')
                ->first();

            return $resource?->board;
        }

        $messageId = $request->route('id');
        if ($messageId && is_numeric($messageId) && str_contains($request->path(), 'pipeline/conversation/messages')) {
            $message = PipelineBoardMessage::query()
                ->where('business_id', $businessId)
                ->whereKey((int) $messageId)
                ->with('board')
                ->first();

            return $message?->board;
        }

        $attachmentId = $request->route('id');
        if ($attachmentId && is_numeric($attachmentId) && str_contains($request->path(), 'pipeline/conversation/attachments')) {
            $attachment = PipelineBoardMessageAttachment::query()
                ->whereKey((int) $attachmentId)
                ->whereHas('message', fn ($query) => $query->where('business_id', $businessId))
                ->with('message.board')
                ->first();

            return $attachment?->message?->board;
        }

        $automationId = $request->route('id');
        if ($automationId && is_numeric($automationId) && str_contains($request->path(), 'pipeline/automations')) {
            $automation = PipelineBoardAutomation::query()
                ->where('business_id', $businessId)
                ->whereKey((int) $automationId)
                ->with('board')
                ->first();

            return $automation?->board;
        }

        $reminderId = $request->route('id');
        if ($reminderId && is_numeric($reminderId) && str_contains($request->path(), 'pipeline/reminders')) {
            $reminder = PipelineReminder::query()
                ->where('business_id', $businessId)
                ->whereKey((int) $reminderId)
                ->with('lead.board')
                ->first();

            return $reminder?->lead?->board;
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
