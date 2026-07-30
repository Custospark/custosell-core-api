<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineLead;
use App\Models\PipelineStage;
use App\Models\Project;
use App\Models\User;
use App\Services\Pipeline\PipelineBoardLookupService;
use App\Services\Pipeline\PipelineBoardPermissionService;
use App\Services\Pipeline\PipelineBoardSeedService;
use App\Services\Pipeline\PipelineMemberService;
use App\Services\Pipeline\PipelineSourceService;
use App\Services\ProjectAccessService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PipelineBoardService
{
    public function __construct(
        protected PipelineBoardLookupService $lookup,
        protected PipelineBoardPermissionService $permission,
        protected PipelineBoardSeedService $boardSeed,
        protected PipelineMemberService $memberService,
        protected PipelineSourceService $sourceService,
        protected ProjectAccessService $projectAccess,
    ) {}

    public function ensureBusinessSetup(int $businessId, int $userId): void
    {
        $this->sourceService->seedSourcesIfMissing($businessId);

        $hasBoard = PipelineBoard::query()
            ->where('business_id', $businessId)
            ->where('is_archived', false)
            ->exists();

        if (!$hasBoard) {
            $this->createBoard($businessId, $userId, [
                'name' => 'Main sales pipeline',
                'description' => 'Default team pipeline',
                'visibility' => 'team',
                'is_default' => true,
                'cover_color' => '#6366f1',
                'workspace' => 'pipeline',
            ]);
        }
    }

    public function getOrCreateProjectBoard(int $businessId, User $user, int $projectId): PipelineBoard
    {
        $project = Project::query()
            ->where('business_id', $businessId)
            ->whereKey($projectId)
            ->firstOrFail();

        $this->projectAccess->assertCanAccessProject($user, $project);

        $existing = PipelineBoard::query()
            ->where('business_id', $businessId)
            ->where('project_id', $projectId)
            ->first();

        if ($existing) {
            return $existing->load(['stages', 'creator']);
        }

        return DB::transaction(function () use ($businessId, $user, $project) {
            $board = PipelineBoard::create([
                'business_id' => $businessId,
                'created_by' => $user->id,
                'name' => $project->name,
                'description' => 'Project board for ' . $project->name,
                'visibility' => 'team',
                'project_id' => $project->id,
                'workspace' => 'estimates',
            ]);

            foreach ($this->projectStages() as $index => $stage) {
                PipelineStage::create([
                    'business_id' => $businessId,
                    'board_id' => $board->id,
                    'name' => $stage['name'],
                    'sort_order' => $index,
                    'color' => $stage['color'],
                    'is_won' => $stage['is_won'],
                    'is_lost' => $stage['is_lost'],
                    'rotting_days' => $stage['rotting_days'],
                ]);
            }

            $this->boardSeed->seedDefaultLabels($businessId, $board->id);
            $this->boardSeed->applyDefaultAppearance($board, (int) $board->id);
            $this->boardSeed->seedGuidingCards($board, $user->id);

            return $board->load(['stages', 'creator']);
        });
    }

    public function createBoard(int $businessId, int $userId, array $data): PipelineBoard
    {
        $stageTemplate = ($data['workspace'] ?? 'pipeline') === 'estimates'
            ? $this->projectStages()
            : $this->defaultStages();

        return DB::transaction(function () use ($businessId, $userId, $data, $stageTemplate) {
            $boardAttributes = [
                'business_id' => $businessId,
                'created_by' => $userId,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'visibility' => $data['visibility'] ?? 'team',
                'cover_color' => $data['cover_color'] ?? null,
                'is_default' => (bool) ($data['is_default'] ?? false),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'workspace' => ($data['workspace'] ?? 'pipeline') === 'estimates' ? 'estimates' : 'pipeline',
            ];

            if (!empty($data['background_type'])) {
                $boardAttributes['background_type'] = $data['background_type'];
                $boardAttributes['background_value'] = $data['background_value'] ?? null;
            }

            $board = PipelineBoard::create($boardAttributes);

            if (!empty($data['member_ids']) && $board->visibility === 'shared') {
                $this->memberService->syncBoardMembers($board, $data['member_ids'], $userId);
            }
            if (!empty($data['members']) && $board->visibility === 'shared') {
                $this->memberService->syncBoardMembers($board, $data['members'], $userId);
            }

            foreach ($stageTemplate as $index => $stage) {
                PipelineStage::create([
                    'business_id' => $businessId,
                    'board_id' => $board->id,
                    'name' => $stage['name'],
                    'sort_order' => $index,
                    'color' => $stage['color'],
                    'is_won' => $stage['is_won'],
                    'is_lost' => $stage['is_lost'],
                    'rotting_days' => $stage['rotting_days'],
                ]);
            }

            $this->boardSeed->seedDefaultLabels($businessId, $board->id);

            if (empty($data['background_type'])) {
                $this->boardSeed->applyDefaultAppearance($board, (int) $board->id);
            } elseif (empty($board->cover_color)) {
                $appearance = $this->boardSeed->defaultAppearance((int) $board->id);
                $board->cover_color = $appearance['cover_color'];
                $board->save();
            }

            $this->boardSeed->seedGuidingCards($board, $userId);

            return $board->load(['stages', 'members.user', 'creator']);
        });
    }

    public function listBoards(int $businessId, User $user, bool $salesOnly = false, bool $projectOnly = false, bool $estimatesWorkspace = false): Collection
    {
        $this->ensureBusinessSetup($businessId, $user->id);

        $query = PipelineBoard::query()
            ->where('is_archived', false)
            ->where(function ($q) use ($businessId, $user) {
                $q->where('business_id', $businessId)
                    ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id));
            })
            ->when($salesOnly, fn ($q) => $q->whereNull('project_id')->where(function ($inner) {
                $inner->where('workspace', 'pipeline')->orWhereNull('workspace');
            }))
            ->when($projectOnly, fn ($q) => $q->whereNotNull('project_id'))
            ->when($estimatesWorkspace, fn ($q) => $q->where(function ($inner) {
                $inner->whereNotNull('project_id')->orWhere('workspace', 'estimates');
            }))
            ->withCount(['leads as open_leads_count' => fn ($q) => $q
                ->where('status', 'open')
                ->when($salesOnly, fn ($inner) => $inner->where('card_type', 'lead'))])
            ->with(['creator:id,name'])
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name');

        return $query->get()
            ->filter(fn (PipelineBoard $board) => $this->permission->canViewBoard($user, $board))
            ->values();
    }

    public function getBoard(int $businessId, User $user, int $boardId): PipelineBoard
    {
        $board = $this->lookup->findBoardForUser($user, $boardId);
        $this->permission->assertCanViewBoard($user, $board);

        return $board->load(['stages', 'members.user', 'creator']);
    }

    public function updateBoard(int $businessId, User $user, int $boardId, array $data): PipelineBoard
    {
        $board = $this->lookup->findBoardForUser($user, $boardId);

        if (array_key_exists('is_archived', $data) && $data['is_archived']) {
            $this->permission->assertCanArchiveBoard($user, $board);
        } else {
            $this->permission->assertCanManageBoard($user, $board);
        }

        $board->update(array_filter([
            'name' => $data['name'] ?? null,
            'description' => array_key_exists('description', $data) ? $data['description'] : $board->description,
            'visibility' => $data['visibility'] ?? null,
            'cover_color' => array_key_exists('cover_color', $data) ? $data['cover_color'] : $board->cover_color,
            'background_type' => array_key_exists('background_type', $data) ? $data['background_type'] : $board->background_type,
            'background_value' => array_key_exists('background_value', $data) ? $data['background_value'] : $board->background_value,
            'is_archived' => array_key_exists('is_archived', $data) ? $data['is_archived'] : null,
        ], fn ($v) => $v !== null));

        if ($board->visibility === 'shared' && (array_key_exists('member_ids', $data) || array_key_exists('members', $data))) {
            $this->permission->assertCanManageBoard($user, $board);
            $members = $data['members'] ?? $data['member_ids'] ?? [];
            $this->memberService->syncBoardMembers($board, $members, (int) $user->id);
        }

        return $board->fresh(['stages', 'members.user', 'creator']);
    }

    public function deleteBoard(int $businessId, User $user, int $boardId): void
    {
        $board = $this->lookup->findBoardForBusiness($businessId, $boardId);
        $this->permission->assertCanManageBoard($user, $board);

        if ($board->is_default) {
            abort(422, 'The default board cannot be deleted.');
        }

        $board->delete();
    }

    public function getKanban(int $businessId, User $user, int $boardId): PipelineBoard
    {
        $board = $this->lookup->findBoardForUser($user, $boardId);
        $this->permission->assertCanViewBoard($user, $board);

        return $board->load([
            'stages.leads' => fn ($q) => $q
                ->whereIn('status', ['open', 'won', 'lost'])
                ->with([
                    'creator:id,name,avatar',
                    'assignee:id,name,avatar',
                    'assignees:id,name,avatar',
                    'source:id,name',
                    'customer:id,name,email,phone',
                    'labels:id,name,color',
                    'checklists.items',
                    'meetings',
                ])
                ->withCount('attachments')
                ->withCount([
                    'activities as comments_count' => fn ($q) => $q->whereIn('type', ['note', 'comment', 'call', 'email', 'meeting']),
                ])
                ->withCount(['activities as history_count'])
                ->orderByRaw('is_pinned DESC, position ASC'),
            'members.user:id,name',
            'creator:id,name',
        ]);
    }

    public function duplicateBoard(int $businessId, User $user, int $boardId): PipelineBoard
    {
        $source = $this->getBoard($businessId, $user, $boardId);
        $this->permission->assertCanEditBoard($user, $source);

        return DB::transaction(function () use ($businessId, $user, $source) {
            $board = PipelineBoard::create([
                'business_id' => $businessId,
                'created_by' => $user->id,
                'name' => 'Copy of ' . $source->name,
                'description' => $source->description,
                'visibility' => $source->visibility,
                'cover_color' => $source->cover_color,
                'background_type' => $source->background_type,
                'background_value' => $source->background_value,
                'workspace' => $source->workspace ?? 'pipeline',
                'sort_order' => 0,
            ]);

            $stageIdMap = [];
            foreach ($source->stages ?? [] as $index => $stage) {
                $newStage = PipelineStage::create([
                    'business_id' => $businessId,
                    'board_id' => $board->id,
                    'name' => $stage->name,
                    'sort_order' => $index,
                    'color' => $stage->color,
                    'is_won' => $stage->is_won,
                    'is_lost' => $stage->is_lost,
                    'rotting_days' => $stage->rotting_days,
                ]);
                $stageIdMap[$stage->id] = $newStage->id;
            }

            $this->boardSeed->seedDefaultLabels($businessId, $board->id);

            if ($source->visibility === 'shared' && $source->members->isNotEmpty()) {
                $memberIds = $source->members->pluck('user_id')->toArray();
                if (!in_array($user->id, $memberIds)) {
                    $memberIds[] = $user->id;
                }
                $this->memberService->syncBoardMembers($board, $memberIds, $user->id);
            }

            $sourceLeads = PipelineLead::query()
                ->where('board_id', $source->id)
                ->whereIn('status', ['open', 'won', 'lost', 'converted'])
                ->with(['labels', 'assignees'])
                ->get();

            foreach ($sourceLeads as $lead) {
                $newStageId = $stageIdMap[$lead->stage_id] ?? null;
                if (!$newStageId) continue;

                $newLead = PipelineLead::create([
                    'business_id' => $businessId,
                    'board_id' => $board->id,
                    'stage_id' => $newStageId,
                    'created_by' => $user->id,
                    'assigned_to' => $lead->assigned_to,
                    'title' => $lead->title,
                    'card_type' => $lead->card_type,
                    'description' => $lead->description,
                    'contact_name' => $lead->contact_name,
                    'contact_email' => $lead->contact_email,
                    'contact_phone' => $lead->contact_phone,
                    'estimated_value' => $lead->estimated_value,
                    'currency' => $lead->currency,
                    'status' => $lead->status,
                    'position' => $lead->position,
                    'priority' => $lead->priority,
                    'due_date' => $lead->due_date,
                    'expected_close_date' => $lead->expected_close_date,
                    'start_date' => $lead->start_date,
                    'background_color' => $lead->background_color,
                ]);

                if ($lead->labels->isNotEmpty()) {
                    $newLead->labels()->sync($lead->labels->pluck('id')->toArray());
                }

                if ($lead->assignees->isNotEmpty()) {
                    $newLead->assignees()->sync($lead->assignees->pluck('id')->toArray());
                }
            }

            return $board->load(['stages', 'members.user', 'creator']);
        });
    }

    private function projectStages(): array
    {
        return [
            ['name' => 'To Do', 'color' => '#64748b', 'is_won' => false, 'is_lost' => false, 'rotting_days' => null],
            ['name' => 'In Progress', 'color' => '#3b82f6', 'is_won' => false, 'is_lost' => false, 'rotting_days' => null],
            ['name' => 'Review', 'color' => '#f59e0b', 'is_won' => false, 'is_lost' => false, 'rotting_days' => null],
            ['name' => 'Done', 'color' => '#10b981', 'is_won' => true, 'is_lost' => false, 'rotting_days' => null],
        ];
    }

    private function defaultStages(): array
    {
        return [
            ['name' => 'New', 'color' => '#6366f1', 'is_won' => false, 'is_lost' => false, 'rotting_days' => 3],
            ['name' => 'Contacted', 'color' => '#3b82f6', 'is_won' => false, 'is_lost' => false, 'rotting_days' => 5],
            ['name' => 'Qualified', 'color' => '#0ea5e9', 'is_won' => false, 'is_lost' => false, 'rotting_days' => 7],
            ['name' => 'Proposal', 'color' => '#8b5cf6', 'is_won' => false, 'is_lost' => false, 'rotting_days' => 10],
            ['name' => 'Negotiation', 'color' => '#f59e0b', 'is_won' => false, 'is_lost' => false, 'rotting_days' => 14],
            ['name' => 'Closed won', 'color' => '#10b981', 'is_won' => true, 'is_lost' => false, 'rotting_days' => null],
            ['name' => 'Closed lost', 'color' => '#ef4444', 'is_won' => false, 'is_lost' => true, 'rotting_days' => null],
        ];
    }
}
