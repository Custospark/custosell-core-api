<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipelineBoard;
use App\Models\PipelineChecklist;
use App\Models\PipelineChecklistItem;
use App\Models\PipelineLabel;
use App\Models\PipelineLead;
use App\Models\PipelineLeadActivity;
use App\Models\PipelineLeadLink;
use App\Models\PipelineSource;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Pipeline\PipelineActivityService;
use App\Services\Pipeline\PipelineBoardLookupService;
use App\Services\Pipeline\PipelineBoardPermissionService;
use App\Services\Pipeline\PipelineBoardService;
use App\Services\Pipeline\PipelineCalendarService;
use App\Services\Pipeline\PipelineChecklistService;
use App\Services\Pipeline\PipelineLabelService;
use App\Services\Pipeline\PipelineLeadLinkService;
use App\Services\Pipeline\PipelineLeadService;
use App\Services\Pipeline\PipelineMemberService;
use App\Services\Pipeline\PipelineSourceService;
use App\Services\Pipeline\PipelineStageService;
use Illuminate\Database\Eloquent\Collection;

class PipelineService
{
    public const PROJECT_STAGES = [
        ['name' => 'To Do', 'color' => '#64748b', 'is_won' => false, 'is_lost' => false, 'rotting_days' => null],
        ['name' => 'In Progress', 'color' => '#3b82f6', 'is_won' => false, 'is_lost' => false, 'rotting_days' => null],
        ['name' => 'Review', 'color' => '#f59e0b', 'is_won' => false, 'is_lost' => false, 'rotting_days' => null],
        ['name' => 'Done', 'color' => '#10b981', 'is_won' => true, 'is_lost' => false, 'rotting_days' => null],
    ];

    public const DEFAULT_LABELS = [
        ['name' => 'Urgent', 'color' => '#ef4444'],
        ['name' => 'Feature', 'color' => '#3b82f6'],
        ['name' => 'Bug', 'color' => '#f59e0b'],
        ['name' => 'Design', 'color' => '#8b5cf6'],
        ['name' => 'Marketing', 'color' => '#10b981'],
        ['name' => 'Research', 'color' => '#64748b'],
    ];

    public const DEFAULT_STAGES = [
        ['name' => 'New', 'color' => '#6366f1', 'is_won' => false, 'is_lost' => false, 'rotting_days' => 3],
        ['name' => 'Contacted', 'color' => '#3b82f6', 'is_won' => false, 'is_lost' => false, 'rotting_days' => 5],
        ['name' => 'Qualified', 'color' => '#0ea5e9', 'is_won' => false, 'is_lost' => false, 'rotting_days' => 7],
        ['name' => 'Proposal', 'color' => '#8b5cf6', 'is_won' => false, 'is_lost' => false, 'rotting_days' => 10],
        ['name' => 'Negotiation', 'color' => '#f59e0b', 'is_won' => false, 'is_lost' => false, 'rotting_days' => 14],
        ['name' => 'Closed won', 'color' => '#10b981', 'is_won' => true, 'is_lost' => false, 'rotting_days' => null],
        ['name' => 'Closed lost', 'color' => '#ef4444', 'is_won' => false, 'is_lost' => true, 'rotting_days' => null],
    ];

    public function __construct(
        protected PipelineBoardLookupService $lookup,
        protected PipelineBoardPermissionService $permission,
        protected PipelineBoardService $boardService,
        protected PipelineSourceService $sourceService,
        protected PipelineLabelService $labelService,
        protected PipelineMemberService $memberService,
        protected PipelineStageService $stageService,
        protected PipelineLeadService $leadService,
        protected PipelineCalendarService $calendarService,
        protected PipelineChecklistService $checklistService,
        protected PipelineLeadLinkService $leadLinkService,
        protected PipelineActivityService $activityService,
    ) {}

    public function ensureBusinessSetup(int $businessId, int $userId): void
    {
        $this->boardService->ensureBusinessSetup($businessId, $userId);
    }

    public function seedSourcesIfMissing(int $businessId): void
    {
        $this->sourceService->seedSourcesIfMissing($businessId);
    }

    public function getOrCreateProjectBoard(int $businessId, User $user, int $projectId): PipelineBoard
    {
        return $this->boardService->getOrCreateProjectBoard($businessId, $user, $projectId);
    }

    public function createBoard(int $businessId, int $userId, array $data): PipelineBoard
    {
        return $this->boardService->createBoard($businessId, $userId, $data);
    }

    public function syncBoardMembers(PipelineBoard $board, array $members, ?int $actorUserId = null): void
    {
        $this->memberService->syncBoardMembers($board, $members, $actorUserId);
    }

    public function listBoardTeamMembers(int $businessId, string $workspace = 'pipeline', string $scope = 'workspace'): array
    {
        return $this->memberService->listBoardTeamMembers($businessId, $workspace, $scope);
    }

    public function listBoards(int $businessId, User $user, bool $salesOnly = false, bool $projectOnly = false, bool $estimatesWorkspace = false): Collection
    {
        return $this->boardService->listBoards($businessId, $user, $salesOnly, $projectOnly, $estimatesWorkspace);
    }

    public function getBoard(int $businessId, User $user, int|string $boardRef): PipelineBoard
    {
        return $this->boardService->getBoard($businessId, $user, $boardRef);
    }

    public function updateBoard(int $businessId, User $user, int|string $boardRef, array $data): PipelineBoard
    {
        return $this->boardService->updateBoard($businessId, $user, $boardRef, $data);
    }

    public function deleteBoard(int $businessId, User $user, int|string $boardRef): void
    {
        $this->boardService->deleteBoard($businessId, $user, $boardRef);
    }

    public function getKanban(int $businessId, User $user, int|string $boardRef): PipelineBoard
    {
        return $this->boardService->getKanban($businessId, $user, $boardRef);
    }

    public function createStage(int $businessId, User $user, int $boardId, array $data): PipelineStage
    {
        return $this->stageService->createStage($businessId, $user, $boardId, $data);
    }

    public function updateStage(int $businessId, User $user, int $stageId, array $data): PipelineStage
    {
        return $this->stageService->updateStage($businessId, $user, $stageId, $data);
    }

    public function reorderStages(int $businessId, User $user, int $boardId, array $stageIdsInOrder): Collection
    {
        return $this->stageService->reorderStages($businessId, $user, $boardId, $stageIdsInOrder);
    }

    public function deleteStage(int $businessId, User $user, int $stageId, ?int $migrateToStageId = null): void
    {
        $this->stageService->deleteStage($businessId, $user, $stageId, $migrateToStageId);
    }

    public function archiveLead(int $businessId, User $user, int $leadId): void
    {
        $this->leadService->archiveLead($businessId, $user, $leadId);
    }

    public function boardCalendar(int $businessId, User $user, int|string $boardRef, int $year, int $month, string $dateField = 'due'): array
    {
        return $this->calendarService->boardCalendar($businessId, $user, $boardRef, $year, $month, $dateField);
    }

    public function allBoardsCalendar(int $businessId, User $user, int $year, int $month, string $dateField = 'due', string $workspace = 'pipeline'): array
    {
        return $this->calendarService->allBoardsCalendar($businessId, $user, $year, $month, $dateField, $workspace);
    }

    public function createSource(int $businessId, User $user, array $data): PipelineSource
    {
        return $this->sourceService->createSource($businessId, $user, $data);
    }

    public function updateSource(int $businessId, int $sourceId, array $data): PipelineSource
    {
        return $this->sourceService->updateSource($businessId, $sourceId, $data);
    }

    public function deleteSource(int $businessId, int $sourceId): void
    {
        $this->sourceService->deleteSource($businessId, $sourceId);
    }

    public function listLeads(int $businessId, User $user, array $filters = []): Collection
    {
        return $this->leadService->listLeads($businessId, $user, $filters);
    }

    public function createLead(int $businessId, User $user, array $data, array $options = []): PipelineLead
    {
        return $this->leadService->createLead($businessId, $user, $data, $options);
    }

    public function getLead(int $businessId, User $user, int $leadId): PipelineLead
    {
        return $this->leadService->getLead($businessId, $user, $leadId);
    }

    public function updateLead(int $businessId, User $user, int $leadId, array $data): PipelineLead
    {
        return $this->leadService->updateLead($businessId, $user, $leadId, $data);
    }

    public function moveLead(int $businessId, User $user, int $leadId, int $stageId, float $position): PipelineLead
    {
        return $this->leadService->moveLead($businessId, $user, $leadId, $stageId, $position);
    }

    public function convertLead(int $businessId, User $user, int $leadId, array $data): PipelineLead
    {
        return $this->leadService->convertLead($businessId, $user, $leadId, $data);
    }

    public function addActivity(int $businessId, User $user, int $leadId, string $type, ?string $body, ?array $metadata = null, ?int $parentId = null): PipelineLeadActivity
    {
        return $this->activityService->addActivity($businessId, $user, $leadId, $type, $body, $metadata, $parentId);
    }

    public function logLeadHistoryEvent(PipelineLead $lead, User $user, string $body, ?array $metadata = null): PipelineLeadActivity
    {
        return $this->activityService->logLeadHistoryEvent($lead, $user, $body, $metadata);
    }

    public function addActivityAndNotify(int $businessId, User $user, int $leadId, string $type, ?string $body, ?array $metadata = null, ?int $parentId = null): PipelineLeadActivity
    {
        return $this->activityService->addActivityAndNotify($businessId, $user, $leadId, $type, $body, $metadata, $parentId);
    }

    public function deleteActivity(int $businessId, User $user, int $activityId): void
    {
        $this->activityService->deleteActivity($businessId, $user, $activityId);
    }

    public function updateActivity(int $businessId, User $user, int $activityId, string $body): PipelineLeadActivity
    {
        return $this->activityService->updateActivity($businessId, $user, $activityId, $body);
    }

    public function userCanManageBoard(User $user, PipelineBoard $board): bool
    {
        return $this->permission->userCanManageBoard($user, $board);
    }

    public function listSources(int $businessId): Collection
    {
        return $this->sourceService->listSources($businessId);
    }

    public function insightsSummary(int $businessId, User $user, ?int $boardId = null): array
    {
        return $this->calendarService->insightsSummary($businessId, $user, $boardId);
    }

    public function listLabels(int $businessId, User $user, ?int $boardId = null): Collection
    {
        return $this->labelService->listLabels($businessId, $user, $boardId);
    }

    public function createLabel(int $businessId, User $user, array $data): PipelineLabel
    {
        return $this->labelService->createLabel($businessId, $user, $data);
    }

    public function updateLabel(int $businessId, User $user, int $labelId, array $data): PipelineLabel
    {
        return $this->labelService->updateLabel($businessId, $user, $labelId, $data);
    }

    public function deleteLabel(int $businessId, User $user, int $labelId): void
    {
        $this->labelService->deleteLabel($businessId, $user, $labelId);
    }

    public function createChecklist(int $businessId, User $user, int $leadId, array $data): PipelineChecklist
    {
        return $this->checklistService->createChecklist($businessId, $user, $leadId, $data);
    }

    public function updateChecklist(int $businessId, User $user, int $checklistId, array $data): PipelineChecklist
    {
        return $this->checklistService->updateChecklist($businessId, $user, $checklistId, $data);
    }

    public function deleteChecklist(int $businessId, User $user, int $checklistId): void
    {
        $this->checklistService->deleteChecklist($businessId, $user, $checklistId);
    }

    public function createChecklistItem(int $businessId, User $user, int $checklistId, array $data): PipelineChecklistItem
    {
        return $this->checklistService->createChecklistItem($businessId, $user, $checklistId, $data);
    }

    public function updateChecklistItem(int $businessId, User $user, int $itemId, array $data): PipelineChecklistItem
    {
        return $this->checklistService->updateChecklistItem($businessId, $user, $itemId, $data);
    }

    public function deleteChecklistItem(int $businessId, User $user, int $itemId): void
    {
        $this->checklistService->deleteChecklistItem($businessId, $user, $itemId);
    }

    public function recordActivity(PipelineLead $lead, ?int $userId, string $type, ?string $body, ?array $metadata = null, ?int $parentId = null): PipelineLeadActivity
    {
        return $this->leadService->recordActivity($lead, $userId, $type, $body, $metadata, $parentId);
    }

    public function findBoardForBusiness(int $businessId, int|string $boardRef): PipelineBoard
    {
        return $this->lookup->findBoardForBusiness($businessId, $boardRef);
    }

    public function findStageForBusiness(int $businessId, int $stageId): PipelineStage
    {
        return $this->lookup->findStageForBusiness($businessId, $stageId);
    }

    public function findLeadForBusiness(int $businessId, int $leadId): PipelineLead
    {
        return $this->lookup->findLeadForBusiness($businessId, $leadId);
    }

    public function findBoardForUser(User $user, int|string $boardRef): PipelineBoard
    {
        return $this->lookup->findBoardForUser($user, $boardRef);
    }

    public function findLeadForUser(User $user, int $leadId): PipelineLead
    {
        return $this->lookup->findLeadForUser($user, $leadId);
    }

    public function findStageForUser(User $user, int $stageId): PipelineStage
    {
        return $this->lookup->findStageForUser($user, $stageId);
    }

    public function canViewBoard(User $user, PipelineBoard $board): bool
    {
        return $this->permission->canViewBoard($user, $board);
    }

    public function ensureCanContributeToBoard(User $user, PipelineBoard $board): void
    {
        $this->permission->ensureCanContributeToBoard($user, $board);
    }

    public function assertCanEditBoard(User $user, PipelineBoard $board): void
    {
        $this->permission->assertCanEditBoard($user, $board);
    }

    public function ensureCanEditBoard(User $user, PipelineBoard $board): void
    {
        $this->permission->ensureCanEditBoard($user, $board);
    }

    public function ensureCanManageBoard(User $user, PipelineBoard $board): void
    {
        $this->permission->ensureCanManageBoard($user, $board);
    }

    public function userCanContributeToBoard(User $user, PipelineBoard $board): bool
    {
        return $this->permission->userCanContributeToBoard($user, $board);
    }

    public function resolveCurrentUserBoardMemberRole(User $user, PipelineBoard $board): ?string
    {
        return $this->permission->resolveCurrentUserBoardMemberRole($user, $board);
    }

    public function boardMemberRoleAllowsEdit(?string $role): bool
    {
        return $this->permission->boardMemberRoleAllowsEdit($role);
    }

    public function boardMemberRoleAllowsManage(?string $role): bool
    {
        return $this->permission->boardMemberRoleAllowsManage($role);
    }

    public function normalizeBoardMemberRole(string $role): string
    {
        return $this->permission->normalizeBoardMemberRole($role);
    }

    public function createLeadLink(int $businessId, User $user, int $leadId, array $data): PipelineLeadLink
    {
        return $this->leadLinkService->createLeadLink($businessId, $user, $leadId, $data);
    }

    public function deleteLeadLink(int $businessId, User $user, int $linkId): void
    {
        $this->leadLinkService->deleteLeadLink($businessId, $user, $linkId);
    }

    public function duplicateBoard(int $businessId, User $user, int|string $boardRef): PipelineBoard
    {
        return $this->boardService->duplicateBoard($businessId, $user, $boardRef);
    }
}
