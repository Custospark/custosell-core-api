<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipelineAttachment;
use App\Models\PipelineBoard;
use App\Models\PipelineBoardMember;
use App\Models\PipelineChecklist;
use App\Models\PipelineChecklistItem;
use App\Models\PipelineLead;
use App\Models\PipelineLeadActivity;
use App\Models\PipelineLabel;
use App\Models\PipelineSource;
use App\Models\PipelineStage;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\PipelineLeadAssignee;
use App\Models\User;
use App\Services\Pipeline\PipelineBoardActivityService;
use App\Services\Pipeline\PipelineBoardAutomationService;
use App\Services\Pipeline\PipelineBoardSeedService;
use App\Services\Pipeline\PipelineCollaborationService;
use App\Services\Pipeline\PipelineNotificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PipelineService
{
    public function __construct(
        protected ModuleAccessService $moduleAccess,
        protected CustomerContactService $customerContactService,
        protected ProjectAccessService $projectAccess,
        protected PipelineNotificationService $pipelineNotifier,
        protected PipelineBoardSeedService $boardSeed,
    ) {}

    /** @return list<array{name: string, color: string|null, is_won: bool, is_lost: bool, rotting_days: int|null}> */
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

    /** @return list<array{name: string, color: string|null, is_won: bool, is_lost: bool, rotting_days: int|null}> */
    public const DEFAULT_STAGES = [
        ['name' => 'New', 'color' => '#6366f1', 'is_won' => false, 'is_lost' => false, 'rotting_days' => 3],
        ['name' => 'Contacted', 'color' => '#3b82f6', 'is_won' => false, 'is_lost' => false, 'rotting_days' => 5],
        ['name' => 'Qualified', 'color' => '#0ea5e9', 'is_won' => false, 'is_lost' => false, 'rotting_days' => 7],
        ['name' => 'Proposal', 'color' => '#8b5cf6', 'is_won' => false, 'is_lost' => false, 'rotting_days' => 10],
        ['name' => 'Negotiation', 'color' => '#f59e0b', 'is_won' => false, 'is_lost' => false, 'rotting_days' => 14],
        ['name' => 'Closed won', 'color' => '#10b981', 'is_won' => true, 'is_lost' => false, 'rotting_days' => null],
        ['name' => 'Closed lost', 'color' => '#ef4444', 'is_won' => false, 'is_lost' => true, 'rotting_days' => null],
    ];

    public function ensureBusinessSetup(int $businessId, int $userId): void
    {
        $this->seedSourcesIfMissing($businessId);

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

    public function seedSourcesIfMissing(int $businessId): void
    {
        if (PipelineSource::query()->where('business_id', $businessId)->exists()) {
            return;
        }

        $defaults = [
            ['name' => 'Walk-in', 'sort_order' => 1],
            ['name' => 'Referral', 'sort_order' => 2],
            ['name' => 'Website', 'sort_order' => 3],
            ['name' => 'Phone', 'sort_order' => 4],
            ['name' => 'Other', 'sort_order' => 5],
        ];

        foreach ($defaults as $row) {
            PipelineSource::create([
                'business_id' => $businessId,
                'name' => $row['name'],
                'is_system' => true,
                'sort_order' => $row['sort_order'],
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

            foreach (self::PROJECT_STAGES as $index => $stage) {
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

    /** @param  array<string, mixed>  $data */
    public function createBoard(int $businessId, int $userId, array $data): PipelineBoard
    {
        $stageTemplate = ($data['workspace'] ?? 'pipeline') === 'estimates'
            ? self::PROJECT_STAGES
            : self::DEFAULT_STAGES;

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
                $this->syncBoardMembers($board, $data['member_ids']);
            }
            if (!empty($data['members']) && $board->visibility === 'shared') {
                $this->syncBoardMembers($board, $data['members']);
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

    /** @param  list<int>|list<array{user_id: int, role?: string}>  $members */
    public function syncBoardMembers(PipelineBoard $board, array $members): void
    {
        PipelineBoardMember::query()->where('board_id', $board->id)->delete();

        foreach ($members as $entry) {
            $userId = is_array($entry) ? (int) ($entry['user_id'] ?? 0) : (int) $entry;
            $role = is_array($entry) ? ($entry['role'] ?? 'contributor') : 'contributor';
            if ($userId === 0 || $userId === (int) $board->created_by) {
                continue;
            }
            $role = $this->normalizeBoardMemberRole($role);
            if (! in_array($role, ['viewer', 'contributor', 'manager'], true)) {
                $role = 'viewer';
            }
            PipelineBoardMember::create([
                'board_id' => $board->id,
                'user_id' => $userId,
                'role' => $role,
            ]);
        }
    }

    /** @return list<array{id: int, name: string, email: string|null, avatar: string|null, modules: list<string>}> */
    public function listBoardTeamMembers(
        int $businessId,
        string $workspace = 'pipeline',
        string $scope = 'workspace',
    ): array {
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
            // Personal/project boards: any active staff in the business can be invited or listed.
            // Full Projects & Estimates admin (estimates_full) is not required.
            return true;
        }

        return in_array('pipeline', $this->moduleAccess->storedStaffModules($user), true);
    }

    protected function seedDefaultLabels(int $businessId, int $boardId): void
    {
        $this->boardSeed->seedDefaultLabels($businessId, $boardId);
    }

    public function listBoards(
        int $businessId,
        User $user,
        bool $salesOnly = false,
        bool $projectOnly = false,
        bool $estimatesWorkspace = false,
    ): Collection {
        $this->ensureBusinessSetup($businessId, $user->id);

        $query = PipelineBoard::query()
            ->where('business_id', $businessId)
            ->where('is_archived', false)
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
            ->filter(fn (PipelineBoard $board) => $this->canViewBoard($user, $board))
            ->values();
    }

    public function getBoard(int $businessId, User $user, int $boardId): PipelineBoard
    {
        $board = $this->findBoardForBusiness($businessId, $boardId);
        $this->assertCanViewBoard($user, $board);

        return $board->load(['stages', 'members.user', 'creator']);
    }

    /** @param  array<string, mixed>  $data */
    public function updateBoard(int $businessId, User $user, int $boardId, array $data): PipelineBoard
    {
        $board = $this->findBoardForBusiness($businessId, $boardId);

        if (array_key_exists('is_archived', $data) && $data['is_archived']) {
            $this->assertCanArchiveBoard($user, $board);
        } else {
            $this->assertCanManageBoard($user, $board);
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
            $this->assertCanManageBoard($user, $board);
            $members = $data['members'] ?? $data['member_ids'] ?? [];
            $this->syncBoardMembers($board, $members);
        }

        return $board->fresh(['stages', 'members.user', 'creator']);
    }

    public function deleteBoard(int $businessId, User $user, int $boardId): void
    {
        $board = $this->findBoardForBusiness($businessId, $boardId);
        $this->assertCanManageBoard($user, $board);

        if ($board->is_default) {
            abort(422, 'The default board cannot be deleted.');
        }

        $board->delete();
    }

    public function getKanban(int $businessId, User $user, int $boardId): PipelineBoard
    {
        $board = $this->findBoardForBusiness($businessId, $boardId);
        $this->assertCanViewBoard($user, $board);

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
                ])
                ->withCount('attachments')
                ->withCount([
                    'activities as comments_count' => fn ($q) => $q->whereIn('type', ['note', 'comment', 'call', 'email', 'meeting']),
                ])
                ->withCount([
                    'activities as history_count',
                ])
                ->orderBy('position'),
            'members.user:id,name',
            'creator:id,name',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function createStage(int $businessId, User $user, int $boardId, array $data): PipelineStage
    {
        $board = $this->findBoardForBusiness($businessId, $boardId);
        $this->assertCanManageBoard($user, $board);

        $maxOrder = PipelineStage::query()->where('board_id', $boardId)->max('sort_order');

        return PipelineStage::create([
            'business_id' => $businessId,
            'board_id' => $boardId,
            'name' => $data['name'],
            'sort_order' => (int) ($data['sort_order'] ?? ($maxOrder + 1)),
            'color' => $data['color'] ?? '#64748b',
            'is_won' => (bool) ($data['is_won'] ?? false),
            'is_lost' => (bool) ($data['is_lost'] ?? false),
            'rotting_days' => $data['rotting_days'] ?? null,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function updateStage(int $businessId, User $user, int $stageId, array $data): PipelineStage
    {
        $stage = $this->findStageForBusiness($businessId, $stageId);
        $this->assertCanManageBoard($user, $stage->board);

        $stage->update(array_filter([
            'name' => $data['name'] ?? null,
            'color' => $data['color'] ?? null,
            'is_won' => array_key_exists('is_won', $data) ? (bool) $data['is_won'] : null,
            'is_lost' => array_key_exists('is_lost', $data) ? (bool) $data['is_lost'] : null,
            'rotting_days' => array_key_exists('rotting_days', $data) ? $data['rotting_days'] : null,
            'sort_order' => $data['sort_order'] ?? null,
        ], fn ($v) => $v !== null));

        return $stage->fresh();
    }

    /** @param  list<int>  $stageIdsInOrder */
    public function reorderStages(int $businessId, User $user, int $boardId, array $stageIdsInOrder): Collection
    {
        $board = $this->findBoardForBusiness($businessId, $boardId);
        $this->assertCanEditBoard($user, $board);

        foreach ($stageIdsInOrder as $order => $stageId) {
            PipelineStage::query()
                ->where('board_id', $boardId)
                ->where('business_id', $businessId)
                ->where('id', $stageId)
                ->update(['sort_order' => $order]);
        }

        return $board->stages()->orderBy('sort_order')->get();
    }

    public function deleteStage(int $businessId, User $user, int $stageId, ?int $migrateToStageId = null): void
    {
        $stage = $this->findStageForBusiness($businessId, $stageId);
        $this->assertCanManageBoard($user, $stage->board);

        $stageCount = PipelineStage::query()->where('board_id', $stage->board_id)->count();
        if ($stageCount <= 1) {
            throw ValidationException::withMessages(['stage' => 'A board must have at least one stage.']);
        }

        $leadCount = PipelineLead::query()->where('stage_id', $stageId)->whereNull('deleted_at')->count();
        if ($leadCount > 0) {
            if (!$migrateToStageId) {
                throw ValidationException::withMessages(['migrate_to_stage_id' => 'Move leads to another stage before deleting.']);
            }
            $target = PipelineStage::query()
                ->where('board_id', $stage->board_id)
                ->where('business_id', $businessId)
                ->where('id', $migrateToStageId)
                ->where('id', '!=', $stageId)
                ->firstOrFail();

            PipelineLead::query()
                ->where('stage_id', $stageId)
                ->update(['stage_id' => $target->id]);
        }

        $stage->delete();
    }

    public function archiveLead(int $businessId, User $user, int $leadId): void
    {
        $lead = $this->findLeadForBusiness($businessId, $leadId);
        $this->assertCanManageBoard($user, $lead->board);

        $this->recordActivity($lead, $user->id, 'system', 'Card archived', [
            'action' => 'archived',
        ]);

        $lead->update(['status' => 'archived']);
        $lead->delete();
    }

    /** @return list<array{date: string, leads: list<array<string, mixed>>}> */
    public function boardCalendar(
        int $businessId,
        User $user,
        int $boardId,
        int $year,
        int $month,
        string $dateField = 'due',
    ): array {
        $board = $this->findBoardForBusiness($businessId, $boardId);
        $this->assertCanViewBoard($user, $board);

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        $query = PipelineLead::query()
            ->where('business_id', $businessId)
            ->where('board_id', $boardId)
            ->whereIn('status', ['open', 'won', 'lost'])
            ->with(['stage:id,name,color', 'assignee:id,name,avatar']);

        if ($dateField === 'start') {
            $query->whereBetween('start_date', [$start, $end]);
        } elseif ($dateField === 'close') {
            $query->whereBetween('expected_close_date', [$start, $end]);
        } elseif ($dateField === 'all') {
            $query->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_date', [$start, $end])
                    ->orWhereBetween('due_date', [$start, $end])
                    ->orWhereBetween('expected_close_date', [$start, $end]);
            });
        } else {
            $query->where(function ($q) use ($start, $end) {
                $q->whereBetween('due_date', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->whereNull('due_date')->whereBetween('expected_close_date', [$start, $end]);
                    });
            });
        }

        $leads = $query->get();
        $byDate = [];

        foreach ($leads as $lead) {
            $entries = $this->calendarDateEntriesForLead($lead, $dateField, $start, $end);
            foreach ($entries as $entry) {
                $byDate[$entry['date']][] = $this->formatCalendarLead($lead, $entry['kind']);
            }
        }

        ksort($byDate);

        return collect($byDate)
            ->map(fn ($group, $date) => [
                'date' => $date,
                'leads' => array_values($group),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{date: string, kind: string}> */
    protected function calendarDateEntriesForLead(
        PipelineLead $lead,
        string $dateField,
        string $rangeStart,
        string $rangeEnd,
    ): array {
        $entries = [];
        $normalizeDate = static function (mixed $date): ?string {
            if ($date === null) {
                return null;
            }
            if ($date instanceof \Carbon\CarbonInterface) {
                return $date->toDateString();
            }

            return substr((string) $date, 0, 10);
        };

        $inRange = static function (?string $date) use ($rangeStart, $rangeEnd): bool {
            if (!$date) {
                return false;
            }

            return $date >= $rangeStart && $date <= $rangeEnd;
        };

        $push = static function (array &$entries, mixed $rawDate, string $kind) use ($normalizeDate, $inRange): void {
            $date = $normalizeDate($rawDate);
            if (!$inRange($date)) {
                return;
            }
            $entries[] = ['date' => $date, 'kind' => $kind];
        };

        if ($dateField === 'start') {
            $push($entries, $lead->start_date, 'start');
        } elseif ($dateField === 'close') {
            $push($entries, $lead->expected_close_date, 'close');
        } elseif ($dateField === 'all') {
            $push($entries, $lead->start_date, 'start');
            $push($entries, $lead->due_date, 'due');
            $push($entries, $lead->expected_close_date, 'close');
        } else {
            if ($lead->due_date) {
                $push($entries, $lead->due_date, 'due');
            } else {
                $push($entries, $lead->expected_close_date, 'close');
            }
        }

        return $entries;
    }

    /** @return array<string, mixed> */
    protected function formatCalendarLead(PipelineLead $lead, string $dateKind): array
    {
        return [
            'id' => $lead->id,
            'title' => $lead->title,
            'card_type' => $lead->card_type ?? 'lead',
            'estimated_value' => $lead->estimated_value !== null ? (float) $lead->estimated_value : null,
            'currency' => $lead->currency,
            'status' => $lead->status,
            'priority' => $lead->priority,
            'date_kind' => $dateKind,
            'stage' => $lead->stage ? [
                'id' => $lead->stage->id,
                'name' => $lead->stage->name,
                'color' => $lead->stage->color,
            ] : null,
            'assignee' => $lead->assignee ? [
                'id' => $lead->assignee->id,
                'name' => $lead->assignee->name,
                'avatar' => $lead->assignee->avatar,
            ] : null,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function createSource(int $businessId, User $user, array $data): PipelineSource
    {
        $maxOrder = PipelineSource::query()->where('business_id', $businessId)->max('sort_order');

        return PipelineSource::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'is_system' => false,
            'sort_order' => (int) ($data['sort_order'] ?? ($maxOrder + 1)),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function updateSource(int $businessId, int $sourceId, array $data): PipelineSource
    {
        $source = PipelineSource::query()
            ->where('business_id', $businessId)
            ->where('id', $sourceId)
            ->firstOrFail();

        if ($source->is_system && array_key_exists('name', $data)) {
            throw ValidationException::withMessages(['name' => 'System sources cannot be renamed.']);
        }

        $source->update(array_filter([
            'name' => $data['name'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
        ], fn ($v) => $v !== null));

        return $source->fresh();
    }

    public function deleteSource(int $businessId, int $sourceId): void
    {
        $source = PipelineSource::query()
            ->where('business_id', $businessId)
            ->where('id', $sourceId)
            ->firstOrFail();

        if ($source->is_system) {
            throw ValidationException::withMessages(['source' => 'System sources cannot be deleted.']);
        }

        PipelineLead::query()->where('source_id', $sourceId)->update(['source_id' => null]);
        $source->delete();
    }

    /** @param  array<string, mixed>  $filters */
    public function listLeads(int $businessId, User $user, array $filters = []): Collection
    {
        $this->ensureBusinessSetup($businessId, $user->id);

        $query = PipelineLead::query()
            ->where('business_id', $businessId)
            ->with(['board', 'stage', 'assignee:id,name,avatar', 'source:id,name', 'customer:id,name,email,phone']);

        if (!empty($filters['board_id'])) {
            $board = $this->findBoardForBusiness($businessId, (int) $filters['board_id']);
            $this->assertCanViewBoard($user, $board);
            $query->where('board_id', $board->id);
        } else {
            $accessibleBoardIds = $this->listBoards($businessId, $user)->pluck('id');
            $query->whereIn('board_id', $accessibleBoardIds);
        }

        if (!empty($filters['assigned_to'])) {
            if ($filters['assigned_to'] === 'me') {
                $query->where(function ($q) use ($user) {
                    $q->where('assigned_to', $user->id)
                        ->orWhereHas('assignees', fn ($aq) => $aq->where('users.id', $user->id));
                });
            } elseif ($filters['assigned_to'] === 'unassigned') {
                $query->whereNull('assigned_to')
                    ->whereDoesntHave('assignees');
            } else {
                $assigneeId = (int) $filters['assigned_to'];
                $query->where(function ($q) use ($assigneeId) {
                    $q->where('assigned_to', $assigneeId)
                        ->orWhereHas('assignees', fn ($aq) => $aq->where('users.id', $assigneeId));
                });
            }
        }

        if (!empty($filters['source_id'])) {
            $query->where('source_id', (int) $filters['source_id']);
        }

        if (!empty($filters['card_type'])) {
            $query->where('card_type', $filters['card_type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->whereIn('status', ['open', 'won', 'lost', 'converted']);
        }

        if (!empty($filters['search'])) {
            $term = '%' . addcslashes($filters['search'], '%_') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', $term)
                    ->orWhere('contact_name', 'like', $term)
                    ->orWhere('contact_email', 'like', $term)
                    ->orWhere('contact_phone', 'like', $term);
            });
        }

        return $query->orderByDesc('updated_at')->get();
    }

    /** @param  array<string, mixed>  $data */
    public function createLead(int $businessId, User $user, array $data): PipelineLead
    {
        $board = $this->findBoardForBusiness($businessId, (int) $data['board_id']);
        $this->assertCanEditBoard($user, $board);

        $stage = PipelineStage::query()
            ->where('board_id', $board->id)
            ->where('business_id', $businessId)
            ->where('id', (int) $data['stage_id'])
            ->firstOrFail();

        $maxPosition = PipelineLead::query()
            ->where('stage_id', $stage->id)
            ->max('position');

        $assigneeIds = $this->resolveAssigneeIds($data, $user);

        $lead = PipelineLead::create([
            'business_id' => $businessId,
            'board_id' => $board->id,
            'stage_id' => $stage->id,
            'created_by' => $user->id,
            'assigned_to' => $assigneeIds[0] ?? $user->id,
            'customer_id' => $data['customer_id'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'title' => $data['title'],
            'card_type' => $data['card_type'] ?? ($board->project_id || $board->workspace === 'estimates' ? 'card' : 'lead'),
            'description' => $data['description'] ?? null,
            'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'estimated_value' => $data['estimated_value'] ?? null,
            'currency' => $data['currency'] ?? 'UGX',
            'status' => 'open',
            'position' => ($maxPosition ?? 0) + 1,
            'expected_close_date' => $data['expected_close_date'] ?? null,
            'due_date' => $data['due_date'] ?? $data['expected_close_date'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'priority' => $data['priority'] ?? null,
        ]);

        if ($board->project_id && ($data['card_type'] ?? 'lead') === 'card') {
            $this->createTaskFromLead($lead, $businessId);
        }

        if (!empty($data['label_ids'])) {
            $lead->labels()->sync($data['label_ids']);
        }

        $newAssignees = $this->syncLeadAssignees($lead, $assigneeIds, $user->id);
        if ($newAssignees !== []) {
            $lead->load('board');
            $this->pipelineNotifier->notifyAssignees(
                $lead,
                $lead->board,
                $user,
                User::query()->whereIn('id', $newAssignees)->get()->all(),
                true,
            );
        }

        $this->recordActivity($lead, $user->id, 'system', ($data['card_type'] ?? 'lead') === 'card' ? 'Card created' : 'Lead created');

        return $this->loadLeadWithHistory($lead);
    }

    public function getLead(int $businessId, User $user, int $leadId): PipelineLead
    {
        $lead = $this->findLeadForBusiness($businessId, $leadId);
        $this->assertCanViewBoard($user, $lead->board);

        return $lead->load(array_merge($this->leadDetailRelations(), [
            'activities' => fn ($q) => $q->with(['user:id,name,avatar', 'reactions'])->orderBy('created_at'),
        ]));
    }

    /** @param  array<string, mixed>  $data */
    public function updateLead(int $businessId, User $user, int $leadId, array $data): PipelineLead
    {
        $lead = $this->findLeadForBusiness($businessId, $leadId);
        $this->assertCanEditBoard($user, $lead->board);
        $lead->load(['labels:id,name', 'assignees:id,name']);

        $before = $lead->replicate();
        $before->setRelation('labels', $lead->labels);
        $before->setRelation('assignees', $lead->assignees);

        $updates = [];
        foreach ([
            'title', 'card_type', 'description', 'assigned_to', 'customer_id', 'source_id',
            'contact_name', 'contact_email', 'contact_phone', 'estimated_value',
            'currency', 'expected_close_date', 'due_date', 'start_date', 'priority',
            'background_color', 'lost_reason', 'status',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if (array_key_exists('status', $data)) {
            $status = $data['status'];
            if ($status === 'won') {
                $updates['won_at'] = now();
                $updates['lost_at'] = null;
            } elseif ($status === 'lost') {
                $updates['lost_at'] = now();
                $updates['won_at'] = null;
            } elseif ($status === 'open') {
                $updates['won_at'] = null;
                $updates['lost_at'] = null;
            }
        }

        if ($updates !== []) {
            $lead->update($updates);
        }

        if (array_key_exists('label_ids', $data)) {
            $lead->labels()->sync($data['label_ids'] ?? []);
        }

        if (array_key_exists('assignee_ids', $data) || array_key_exists('assigned_to', $data)) {
            $assigneeIds = $this->resolveAssigneeIds($data, $user, $lead);
            $newAssignees = $this->syncLeadAssignees($lead, $assigneeIds, $user->id);
            if ($newAssignees !== []) {
                $lead->load('board');
                $this->pipelineNotifier->notifyAssignees(
                    $lead,
                    $lead->board,
                    $user,
                    User::query()->whereIn('id', $newAssignees)->get()->all(),
                    false,
                );
            }
        }

        $lead->refresh();
        $this->recordLeadUpdateActivities($lead, $user, $before, $data);

        if (array_key_exists('status', $data)) {
            $newStatus = (string) ($lead->status ?? 'open');
            $oldStatus = (string) ($before->status ?? 'open');
            if ($newStatus !== $oldStatus && in_array($newStatus, ['won', 'lost'], true)) {
                $lead->load('board');
                app(PipelineBoardAutomationService::class)->runForLeadStatusChange($lead, $lead->board, $newStatus, $user);
                app(PipelineBoardActivityService::class)->log(
                    $lead->board,
                    $user,
                    'lead_status',
                    "Marked {$lead->title} as ".strtoupper($newStatus),
                    null,
                    'lead',
                    (int) $lead->id,
                    ['status' => $newStatus],
                );
            }
        }

        return $this->loadLeadWithHistory($lead);
    }

    public function moveLead(int $businessId, User $user, int $leadId, int $stageId, float $position): PipelineLead
    {
        $lead = $this->findLeadForBusiness($businessId, $leadId);
        $this->assertCanEditBoard($user, $lead->board);

        $stage = PipelineStage::query()
            ->where('board_id', $lead->board_id)
            ->where('business_id', $businessId)
            ->where('id', $stageId)
            ->firstOrFail();

        $fromStageId = $lead->stage_id;
        $fromStatus = $lead->status;
        $status = 'open';
        $wonAt = null;
        $lostAt = null;

        if ($stage->is_won) {
            $status = 'won';
            $wonAt = now();
        } elseif ($stage->is_lost) {
            $status = 'lost';
            $lostAt = now();
        }

        $lead->update([
            'stage_id' => $stage->id,
            'position' => $position,
            'status' => $status,
            'won_at' => $wonAt,
            'lost_at' => $lostAt,
        ]);

        if ($lead->project_task_id) {
            $this->syncTaskStatusFromStage($lead, $stage);
        }

        if ($fromStageId !== $stage->id) {
            $fromStage = $fromStageId
                ? PipelineStage::query()->whereKey($fromStageId)->first()
                : null;

            $fromName = $fromStage?->name ?? 'Previous stage';
            $this->recordActivity($lead, $user->id, 'stage_change', "Moved from {$fromName} to {$stage->name}", [
                'from_stage_id' => $fromStageId,
                'to_stage_id' => $stage->id,
                'from_stage_name' => $fromName,
                'to_stage_name' => $stage->name,
            ]);
        }

        if ($fromStatus !== $status) {
            $this->recordActivity($lead, $user->id, 'system', $this->statusChangeMessage(
                (string) $fromStatus,
                (string) $status,
                (string) ($lead->card_type ?? 'lead'),
            ), [
                'action' => 'status_change',
                'from' => $fromStatus,
                'to' => $status,
                'card_type' => $lead->card_type ?? 'lead',
            ]);
        }

        $lead->load('board');
        if ($fromStageId !== $stage->id) {
            app(PipelineBoardActivityService::class)->log(
                $lead->board,
                $user,
                'lead_moved',
                "Moved {$lead->title} to {$stage->name}",
                null,
                'lead',
                (int) $lead->id,
                ['stage_id' => $stage->id, 'stage_name' => $stage->name],
            );
            app(PipelineBoardAutomationService::class)->runForLeadStageChange($lead, $lead->board, $stage, $user);
        }
        if ($fromStatus !== $status && in_array($status, ['won', 'lost'], true)) {
            app(PipelineBoardAutomationService::class)->runForLeadStatusChange($lead, $lead->board, $status, $user);
        }

        return $this->loadLeadWithHistory($lead);
    }

    protected function syncTaskStatusFromStage(PipelineLead $lead, PipelineStage $stage): void
    {
        $statusMap = [
            'To Do' => 'todo',
            'In Progress' => 'in_progress',
            'Review' => 'in_progress',
            'Done' => 'done',
        ];

        $taskStatus = $statusMap[$stage->name] ?? 'todo';

        ProjectTask::query()
            ->where('id', $lead->project_task_id)
            ->update(['status' => $taskStatus]);
    }

    protected function createTaskFromLead(PipelineLead $lead, int $businessId): void
    {
        $stageName = $lead->stage?->name;
        $statusMap = [
            'To Do' => 'todo',
            'In Progress' => 'in_progress',
            'Review' => 'in_progress',
            'Done' => 'done',
        ];
        $taskStatus = $statusMap[$stageName] ?? 'todo';

        $task = ProjectTask::create([
            'project_id' => $lead->board->project_id,
            'name' => $lead->title,
            'description' => $lead->description,
            'status' => $taskStatus,
            'estimated_hours' => 0,
            'actual_hours' => 0,
            'budget_cost' => 0,
            'assigned_to' => $lead->assigned_to,
        ]);

        $lead->update(['project_task_id' => $task->id]);
    }

    /** @param  array<string, mixed>  $data */
    public function convertLead(int $businessId, User $user, int $leadId, array $data): PipelineLead
    {
        $lead = $this->findLeadForBusiness($businessId, $leadId);
        $this->assertCanEditBoard($user, $lead->board);

        if ($lead->status === 'converted') {
            throw ValidationException::withMessages(['lead' => 'Lead is already converted.']);
        }

        $customerId = $data['customer_id'] ?? $lead->customer_id;

        if (!$customerId) {
            $customer = $this->customerContactService->resolve($businessId, [
                'name' => $lead->contact_name ?: $lead->title,
                'email' => $lead->contact_email,
                'phone' => $lead->contact_phone,
            ]);
            $customerId = $customer->id;
        }

        $lead->update([
            'customer_id' => $customerId,
            'converted_customer_id' => $customerId,
            'status' => 'converted',
            'converted_at' => now(),
        ]);

        $this->recordActivity($lead, $user->id, 'system', 'Lead converted to customer', [
            'customer_id' => $customerId,
        ]);

        return $this->loadLeadWithHistory($lead);
    }

    public function addActivity(
        int $businessId,
        User $user,
        int $leadId,
        string $type,
        ?string $body,
        ?array $metadata = null,
        ?int $parentId = null,
    ): PipelineLeadActivity {
        $lead = $this->findLeadForBusiness($businessId, $leadId);
        $this->assertCanEditBoard($user, $lead->board);

        if ($parentId !== null) {
            $parent = PipelineLeadActivity::query()
                ->where('business_id', $businessId)
                ->where('lead_id', $leadId)
                ->whereKey($parentId)
                ->firstOrFail();

            if (! in_array($parent->type, ['note', 'comment', 'call', 'email', 'meeting'], true)) {
                abort(422, 'You can only reply to user comments.');
            }

            if ($parent->parent_id !== null) {
                abort(422, 'Replies cannot be nested further — reply to the main comment instead.');
            }
        }

        return $this->recordActivity($lead, $user->id, $type, $body, $metadata, $parentId);
    }

    public function logLeadHistoryEvent(
        PipelineLead $lead,
        User $user,
        string $body,
        ?array $metadata = null,
    ): PipelineLeadActivity {
        return $this->recordActivity($lead, $user->id, 'system', $body, $metadata);
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
            $lead = $this->findLeadForBusiness($businessId, $leadId);
            $lead->load('board');
            $recipients = app(PipelineCollaborationService::class)->leadNotificationRecipients($lead, $user);
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
            ->where('business_id', $businessId)
            ->whereKey($activityId)
            ->firstOrFail();

        if (! in_array($activity->type, ['note', 'comment', 'call', 'email', 'meeting'], true)) {
            abort(403, 'This activity cannot be deleted.');
        }

        $lead = $this->findLeadForBusiness($businessId, (int) $activity->lead_id);
        $board = $lead->board ?? $this->findBoardForBusiness($businessId, (int) $lead->board_id);

        $isAuthor = (int) $activity->user_id === (int) $user->id;
        $canModerate = $this->userCanManageBoard($user, $board);

        if (! $isAuthor && ! $canModerate) {
            abort(403, 'You can only delete your own comments or moderate as a board manager.');
        }

        if (in_array($activity->type, ['note', 'comment', 'call', 'email', 'meeting'], true)) {
            $preview = $activity->body ? mb_substr($activity->body, 0, 120) : null;
            $this->logLeadHistoryEvent($lead, $user, 'Comment removed', [
                'action' => 'comment_removed',
                'comment_type' => $activity->type,
                'preview' => $preview,
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
            ->where('business_id', $businessId)
            ->whereKey($activityId)
            ->firstOrFail();

        if (! in_array($activity->type, ['note', 'comment', 'call', 'email', 'meeting'], true)) {
            abort(403, 'This activity cannot be edited.');
        }

        $lead = $this->findLeadForBusiness($businessId, (int) $activity->lead_id);
        $board = $lead->board ?? $this->findBoardForBusiness($businessId, (int) $lead->board_id);

        $isAuthor = (int) $activity->user_id === (int) $user->id;

        if (! $isAuthor) {
            abort(403, 'You can only edit your own comments.');
        }

        $beforeBody = $activity->body;
        $activity->update(['body' => $body]);

        if ($beforeBody !== $body) {
            $this->logLeadHistoryEvent($lead, $user, 'Comment edited', [
                'action' => 'comment_edited',
                'comment_type' => $activity->type,
                'preview' => mb_substr($body, 0, 120),
            ]);
        }

        return $activity->fresh(['user:id,name,avatar', 'reactions']);
    }

    public function userCanManageBoard(User $user, PipelineBoard $board): bool
    {
        if ($board->visibility === 'private' && ! $board->project_id) {
            return (int) $board->created_by === (int) $user->id;
        }

        if ($this->moduleAccess->isBusinessOwner($user)) {
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

    public function listSources(int $businessId): Collection
    {
        $this->seedSourcesIfMissing($businessId);

        return PipelineSource::query()
            ->where('business_id', $businessId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /** @return array<string, mixed> */
    public function insightsSummary(int $businessId, User $user, ?int $boardId = null): array
    {
        $boards = $boardId
            ? collect([$this->getBoard($businessId, $user, $boardId)])
            : $this->listBoards($businessId, $user, salesOnly: true);

        $boards = $boards->filter(fn (PipelineBoard $board) => $board->project_id === null)->values();
        $boardIds = $boards->pluck('id');

        if ($boardIds->isEmpty()) {
            return [
                'open_leads' => 0,
                'open_pipeline_value' => 0,
                'won_leads' => 0,
                'lost_leads' => 0,
                'converted_leads' => 0,
                'win_rate_percent' => 0,
                'by_stage' => [],
                'by_source' => [],
            ];
        }

        $leads = PipelineLead::query()
            ->where('business_id', $businessId)
            ->whereIn('board_id', $boardIds)
            ->where('card_type', 'lead')
            ->whereIn('status', ['open', 'won', 'lost', 'converted'])
            ->with(['stage:id,name,is_won,is_lost,color,sort_order', 'source:id,name'])
            ->get();

        $openLeads = $leads->where('status', 'open');
        $wonLeads = $leads->where('status', 'won');
        $lostLeads = $leads->where('status', 'lost');
        $convertedLeads = $leads->where('status', 'converted');

        $byStage = $openLeads->groupBy('stage_id')->map(function ($group, $stageId) {
            $stage = $group->first()->stage;

            return [
                'stage_id' => (int) $stageId,
                'stage_name' => $stage?->name ?? 'Unknown',
                'color' => $stage?->color,
                'sort_order' => $stage?->sort_order ?? 0,
                'count' => $group->count(),
                'value' => round((float) $group->sum('estimated_value'), 2),
            ];
        })->sortBy('sort_order')->values();

        $bySource = $openLeads->groupBy('source_id')->map(function ($group, $sourceId) {
            $source = $group->first()->source;

            return [
                'source_id' => $sourceId ? (int) $sourceId : null,
                'source_name' => $source?->name ?? 'No source',
                'count' => $group->count(),
                'value' => round((float) $group->sum('estimated_value'), 2),
            ];
        })->sortByDesc('count')->values();

        $totalOpen = $openLeads->count();
        $closed = $wonLeads->count() + $lostLeads->count();
        $winRate = $closed > 0 ? round(($wonLeads->count() / $closed) * 100, 1) : 0;

        return [
            'open_leads' => $totalOpen,
            'open_pipeline_value' => round((float) $openLeads->sum('estimated_value'), 2),
            'won_leads' => $wonLeads->count(),
            'lost_leads' => $lostLeads->count(),
            'converted_leads' => $convertedLeads->count(),
            'win_rate_percent' => $winRate,
            'by_stage' => $byStage,
            'by_source' => $bySource,
        ];
    }

    public function listLabels(int $businessId, User $user, ?int $boardId = null): Collection
    {
        if ($boardId) {
            $board = $this->findBoardForBusiness($businessId, $boardId);
            $this->assertCanViewBoard($user, $board);
        }

        $labels = PipelineLabel::query()
            ->where('business_id', $businessId)
            ->when($boardId, fn ($q) => $q->where('board_id', $boardId))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($boardId && $labels->isEmpty()) {
            $this->seedDefaultLabels($businessId, $boardId);

            return PipelineLabel::query()
                ->where('business_id', $businessId)
                ->where('board_id', $boardId)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();
        }

        return $labels;
    }

    /** @param  array<string, mixed>  $data */
    public function createLabel(int $businessId, User $user, array $data): PipelineLabel
    {
        if (!empty($data['board_id'])) {
            $board = $this->findBoardForBusiness($businessId, (int) $data['board_id']);
            $this->assertCanEditBoard($user, $board);
        }

        $maxOrder = PipelineLabel::query()
            ->where('business_id', $businessId)
            ->where('board_id', $data['board_id'] ?? null)
            ->max('sort_order');

        return PipelineLabel::create([
            'business_id' => $businessId,
            'board_id' => $data['board_id'] ?? null,
            'name' => $data['name'],
            'color' => $data['color'] ?? '#6366f1',
            'sort_order' => (int) ($data['sort_order'] ?? ($maxOrder + 1)),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function updateLabel(int $businessId, User $user, int $labelId, array $data): PipelineLabel
    {
        $label = PipelineLabel::query()
            ->where('business_id', $businessId)
            ->where('id', $labelId)
            ->firstOrFail();

        if ($label->board_id) {
            $board = $this->findBoardForBusiness($businessId, $label->board_id);
            $this->assertCanEditBoard($user, $board);
        }

        $label->update(array_filter([
            'name' => $data['name'] ?? null,
            'color' => $data['color'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
        ], fn ($v) => $v !== null));

        return $label->fresh();
    }

    public function deleteLabel(int $businessId, User $user, int $labelId): void
    {
        $label = PipelineLabel::query()
            ->where('business_id', $businessId)
            ->where('id', $labelId)
            ->firstOrFail();

        if ($label->board_id) {
            $board = $this->findBoardForBusiness($businessId, $label->board_id);
            $this->assertCanEditBoard($user, $board);
        }

        $label->delete();
    }

    /** @param  array<string, mixed>  $data */
    public function createChecklist(int $businessId, User $user, int $leadId, array $data): PipelineChecklist
    {
        $lead = $this->findLeadForBusiness($businessId, $leadId);
        $this->assertCanEditBoard($user, $lead->board);

        $maxOrder = PipelineChecklist::query()->where('lead_id', $leadId)->max('sort_order');
        $title = $data['title'] ?? 'Checklist';

        $checklist = PipelineChecklist::create([
            'lead_id' => $leadId,
            'title' => $title,
            'description' => $data['description'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? ($maxOrder + 1)),
        ]);

        $this->recordActivity($lead, $user->id, 'system', "Checklist added: {$title}", [
            'action' => 'checklist_added',
            'title' => $title,
        ]);

        return $checklist;
    }

    /** @param  array<string, mixed>  $data */
    public function updateChecklist(int $businessId, User $user, int $checklistId, array $data): PipelineChecklist
    {
        $checklist = PipelineChecklist::query()->with('lead.board')->findOrFail($checklistId);
        $this->assertCanEditBoard($user, $checklist->lead->board);

        $payload = [];
        if (array_key_exists('title', $data)) {
            $payload['title'] = $data['title'];
        }
        if (array_key_exists('description', $data)) {
            $payload['description'] = $data['description'];
        }
        if (array_key_exists('sort_order', $data)) {
            $payload['sort_order'] = $data['sort_order'];
        }
        $checklist->update($payload);

        return $checklist->fresh('items');
    }

    public function deleteChecklist(int $businessId, User $user, int $checklistId): void
    {
        $checklist = PipelineChecklist::query()->with('lead.board')->findOrFail($checklistId);
        $this->assertCanEditBoard($user, $checklist->lead->board);
        $title = $checklist->title;

        $checklist->delete();

        $this->recordActivity($checklist->lead, $user->id, 'system', "Checklist removed: {$title}", [
            'action' => 'checklist_removed',
            'title' => $title,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function createChecklistItem(int $businessId, User $user, int $checklistId, array $data): PipelineChecklistItem
    {
        $checklist = PipelineChecklist::query()->with('lead.board')->findOrFail($checklistId);
        $this->assertCanEditBoard($user, $checklist->lead->board);

        $maxOrder = PipelineChecklistItem::query()->where('checklist_id', $checklistId)->max('sort_order');
        $title = $data['title'];

        $item = PipelineChecklistItem::create([
            'checklist_id' => $checklistId,
            'title' => $title,
            'description' => $data['description'] ?? null,
            'is_done' => (bool) ($data['is_done'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? ($maxOrder + 1)),
        ]);

        $this->recordActivity($checklist->lead, $user->id, 'system', "Checklist item added: {$title}", [
            'action' => 'checklist_item_added',
            'title' => $title,
        ]);

        return $item;
    }

    /** @param  array<string, mixed>  $data */
    public function updateChecklistItem(int $businessId, User $user, int $itemId, array $data): PipelineChecklistItem
    {
        $item = PipelineChecklistItem::query()
            ->with('checklist.lead.board')
            ->findOrFail($itemId);
        $this->assertCanEditBoard($user, $item->checklist->lead->board);

        $wasDone = (bool) $item->is_done;

        $payload = [];
        if (array_key_exists('title', $data)) {
            $payload['title'] = $data['title'];
        }
        if (array_key_exists('description', $data)) {
            $payload['description'] = $data['description'];
        }
        if (array_key_exists('is_done', $data)) {
            $payload['is_done'] = (bool) $data['is_done'];
        }
        if (array_key_exists('sort_order', $data)) {
            $payload['sort_order'] = $data['sort_order'];
        }
        $item->update($payload);

        if (array_key_exists('is_done', $data) && (bool) $data['is_done'] !== $wasDone) {
            $message = (bool) $data['is_done']
                ? "Checklist item completed: {$item->title}"
                : "Checklist item reopened: {$item->title}";
            $this->recordActivity($item->checklist->lead, $user->id, 'system', $message, [
                'action' => (bool) $data['is_done'] ? 'checklist_item_done' : 'checklist_item_reopened',
                'title' => $item->title,
            ]);
        }

        return $item->fresh();
    }

    public function deleteChecklistItem(int $businessId, User $user, int $itemId): void
    {
        $item = PipelineChecklistItem::query()
            ->with('checklist.lead.board')
            ->findOrFail($itemId);
        $this->assertCanEditBoard($user, $item->checklist->lead->board);
        $title = $item->title;
        $lead = $item->checklist->lead;

        $item->delete();

        $this->recordActivity($lead, $user->id, 'system', "Checklist item removed: {$title}", [
            'action' => 'checklist_item_removed',
            'title' => $title,
        ]);
    }

    public function addAttachment(int $businessId, User $user, int $leadId, UploadedFile $file): PipelineAttachment
    {
        $lead = $this->findLeadForBusiness($businessId, $leadId);
        $this->assertCanEditBoard($user, $lead->board);

        $path = $file->store('pipeline-attachments', 'public');
        $fileName = $file->getClientOriginalName();

        $attachment = PipelineAttachment::create([
            'lead_id' => $leadId,
            'user_id' => $user->id,
            'file_name' => $fileName,
            'file_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
        ]);

        $this->recordActivity($lead, $user->id, 'system', "Attachment added: {$fileName}", [
            'action' => 'attachment_added',
            'file_name' => $fileName,
        ]);

        return $attachment;
    }

    public function deleteAttachment(int $businessId, User $user, int $attachmentId): void
    {
        $attachment = PipelineAttachment::query()
            ->with('lead.board')
            ->findOrFail($attachmentId);

        if ((int) $attachment->lead->business_id !== $businessId) {
            abort(404);
        }

        $this->assertCanEditBoard($user, $attachment->lead->board);

        if ($attachment->file_path) {
            Storage::disk('public')->delete($attachment->file_path);
        }

        $fileName = $attachment->file_name;
        $lead = $attachment->lead;

        $attachment->delete();

        $this->recordActivity($lead, $user->id, 'system', "Attachment removed: {$fileName}", [
            'action' => 'attachment_removed',
            'file_name' => $fileName,
        ]);
    }

    /** @return list<string> */
    protected function leadDetailRelations(): array
    {
        return [
            'board',
            'stage',
            'assignee:id,name,avatar',
            'assignees:id,name,avatar',
            'source:id,name',
            'customer:id,name,email,phone',
            'convertedCustomer:id,name,email,phone',
            'labels:id,name,color',
            'checklists.items',
            'attachments.user:id,name',
        ];
    }

    protected function loadLeadWithHistory(PipelineLead $lead): PipelineLead
    {
        $lead->load(array_merge($this->leadDetailRelations(), [
            'activities' => fn ($q) => $q->with(['user:id,name,avatar', 'reactions'])->orderBy('created_at'),
        ]));

        $lead->loadCount([
            'activities as comments_count' => fn ($q) => $q->whereIn('type', ['note', 'comment', 'call', 'email', 'meeting']),
            'activities as history_count',
        ]);

        return $lead;
    }

    protected function recordActivity(
        PipelineLead $lead,
        ?int $userId,
        string $type,
        ?string $body,
        ?array $metadata = null,
        ?int $parentId = null,
    ): PipelineLeadActivity {
        return PipelineLeadActivity::create([
            'business_id' => $lead->business_id,
            'lead_id' => $lead->id,
            'parent_id' => $parentId,
            'user_id' => $userId,
            'type' => $type,
            'body' => $body,
            'metadata' => $metadata,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    protected function recordLeadUpdateActivities(
        PipelineLead $lead,
        User $user,
        PipelineLead $before,
        array $data,
    ): void {
        $userId = $user->id;

        $scalarFields = [
            'title' => 'Title',
            'description' => 'Description',
            'due_date' => 'Due date',
            'expected_close_date' => 'Expected close',
            'start_date' => 'Start date',
            'priority' => 'Priority',
            'estimated_value' => 'Estimated value',
            'background_color' => 'Card color',
            'lost_reason' => 'Lost reason',
            'contact_name' => 'Contact name',
            'contact_email' => 'Contact email',
            'contact_phone' => 'Contact phone',
        ];

        foreach ($scalarFields as $field => $label) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $from = $before->{$field};
            $to = $lead->{$field};
            if ($this->normalizeActivityValue($from) === $this->normalizeActivityValue($to)) {
                continue;
            }

            $this->recordActivity($lead, $userId, 'system', "{$label} updated", [
                'action' => 'field_change',
                'field' => $field,
                'field_label' => $label,
                'from' => $from,
                'to' => $to,
            ]);
        }

        if (array_key_exists('label_ids', $data)) {
            $beforeNames = $before->labels->pluck('name')->values()->all();
            $lead->load('labels:id,name');
            $afterNames = $lead->labels->pluck('name')->values()->all();

            if ($beforeNames !== $afterNames) {
                $this->recordActivity($lead, $userId, 'system', 'Labels updated', [
                    'action' => 'labels_change',
                    'from' => $beforeNames,
                    'to' => $afterNames,
                ]);
            }
        }

        if (array_key_exists('assignee_ids', $data) || array_key_exists('assigned_to', $data)) {
            $beforeNames = $before->assignees->pluck('name')->values()->all();
            $lead->load('assignees:id,name');
            $afterNames = $lead->assignees->pluck('name')->values()->all();

            if ($beforeNames !== $afterNames) {
                $this->recordActivity($lead, $userId, 'system', 'Assignees updated', [
                    'action' => 'assignees_change',
                    'from' => $beforeNames,
                    'to' => $afterNames,
                ]);
            }
        }

        if (array_key_exists('source_id', $data)) {
            $beforeSource = $before->source_id
                ? PipelineSource::query()->whereKey($before->source_id)->value('name')
                : null;
            $afterSource = $lead->source_id
                ? PipelineSource::query()->whereKey($lead->source_id)->value('name')
                : null;
            if ($this->normalizeActivityValue($beforeSource) !== $this->normalizeActivityValue($afterSource)) {
                $this->recordActivity($lead, $userId, 'system', 'Source updated', [
                    'action' => 'field_change',
                    'field' => 'source_id',
                    'field_label' => 'Source',
                    'from' => $beforeSource,
                    'to' => $afterSource,
                ]);
            }
        }

        if (array_key_exists('status', $data) && $before->status !== $lead->status) {
            $this->recordActivity($lead, $userId, 'system', $this->statusChangeMessage(
                (string) $before->status,
                (string) $lead->status,
                (string) ($lead->card_type ?? 'lead'),
            ), [
                'action' => 'status_change',
                'from' => $before->status,
                'to' => $lead->status,
                'card_type' => $lead->card_type ?? 'lead',
            ]);
        }
    }

    protected function statusChangeMessage(string $from, string $to, string $cardType): string
    {
        $isTask = $cardType === 'card';

        if ($to === 'won') {
            return $isTask ? 'Task marked complete' : 'Lead marked won';
        }
        if ($to === 'lost') {
            return $isTask ? 'Task marked lost' : 'Lead marked lost';
        }
        if ($from === 'won' && $to === 'open') {
            return $isTask ? 'Task marked incomplete' : 'Lead reopened';
        }
        if ($from === 'lost' && $to === 'open') {
            return $isTask ? 'Task reopened' : 'Lead reopened';
        }

        $toLabel = match ($to) {
            'converted' => 'Converted',
            'archived' => 'Archived',
            default => ucfirst($to),
        };

        return $isTask ? "Task status changed to {$toLabel}" : "Lead status changed to {$toLabel}";
    }

    protected function normalizeActivityValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }

    protected function findBoardForBusiness(int $businessId, int $boardId): PipelineBoard
    {
        return PipelineBoard::query()
            ->where('business_id', $businessId)
            ->where('id', $boardId)
            ->firstOrFail();
    }

    protected function findStageForBusiness(int $businessId, int $stageId): PipelineStage
    {
        return PipelineStage::query()
            ->where('business_id', $businessId)
            ->where('id', $stageId)
            ->with('board')
            ->firstOrFail();
    }

    protected function findLeadForBusiness(int $businessId, int $leadId): PipelineLead
    {
        return PipelineLead::query()
            ->where('business_id', $businessId)
            ->where('id', $leadId)
            ->with('board')
            ->firstOrFail();
    }

    public function canViewBoard(User $user, PipelineBoard $board): bool
    {
        if ($board->visibility === 'private' && ! $board->project_id) {
            return (int) $board->created_by === (int) $user->id;
        }

        if ($this->moduleAccess->isBusinessOwner($user)) {
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
                || PipelineBoardMember::query()
                    ->where('board_id', $board->id)
                    ->where('user_id', $user->id)
                    ->exists(),
            default => false,
        };
    }

    protected function assertCanViewBoard(User $user, PipelineBoard $board): void
    {
        if (!$this->canViewBoard($user, $board)) {
            abort(403, 'You do not have access to this pipeline board.');
        }
    }

    public function ensureCanContributeToBoard(User $user, PipelineBoard $board): void
    {
        if (! $this->userCanContributeToBoard($user, $board)) {
            abort(403, 'You have read-only access to this board.');
        }
    }

    protected function assertCanEditBoard(User $user, PipelineBoard $board): void
    {
        $this->assertCanViewBoard($user, $board);

        if ($this->moduleAccess->isBusinessOwner($user) || (int) $board->created_by === (int) $user->id) {
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

    public function normalizeBoardMemberRole(string $role): string
    {
        return match ($role) {
            'editor' => 'contributor',
            'viewer', 'contributor', 'manager' => $role,
            default => 'viewer',
        };
    }

    public function userCanContributeToBoard(User $user, PipelineBoard $board): bool
    {
        if (! $this->canViewBoard($user, $board)) {
            return false;
        }

        if ($board->visibility === 'private' && ! $board->project_id) {
            return (int) $board->created_by === (int) $user->id;
        }

        if ($this->moduleAccess->isBusinessOwner($user) || (int) $board->created_by === (int) $user->id) {
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

            return $member && $this->boardMemberRoleAllowsEdit($member->role);
        }

        return false;
    }

    public function resolveCurrentUserBoardMemberRole(User $user, PipelineBoard $board): ?string
    {
        if ($this->moduleAccess->isBusinessOwner($user) || (int) $board->created_by === (int) $user->id) {
            return 'manager';
        }

        if ($board->project_id) {
            $project = Project::query()->find($board->project_id);
            if ($project && (int) $project->created_by === (int) $user->id) {
                return 'manager';
            }

            $projectMember = \App\Models\ProjectMember::query()
                ->where('project_id', $board->project_id)
                ->where('user_id', $user->id)
                ->first();

            if ($projectMember) {
                return $this->normalizeBoardMemberRole((string) $projectMember->role);
            }
        }

        if ($board->visibility === 'team') {
            return $this->userCanManageBoard($user, $board) ? 'manager' : 'contributor';
        }

        if ($board->visibility !== 'shared') {
            return null;
        }

        $member = PipelineBoardMember::query()
            ->where('board_id', $board->id)
            ->where('user_id', $user->id)
            ->first();

        return $member ? $this->normalizeBoardMemberRole((string) $member->role) : null;
    }

    public function boardMemberRoleAllowsEdit(?string $role): bool
    {
        $normalized = $this->normalizeBoardMemberRole((string) $role);

        return in_array($normalized, ['contributor', 'manager'], true);
    }

    public function boardMemberRoleAllowsManage(?string $role): bool
    {
        return $this->normalizeBoardMemberRole((string) $role) === 'manager';
    }

    public function ensureCanEditBoard(User $user, PipelineBoard $board): void
    {
        $this->assertCanEditBoard($user, $board);
    }

    public function ensureCanManageBoard(User $user, PipelineBoard $board): void
    {
        $this->assertCanManageBoard($user, $board);
    }

    protected function assertCanManageBoard(User $user, PipelineBoard $board): void
    {
        if ($this->userCanManageBoard($user, $board)) {
            return;
        }

        abort(403, $board->project_id
            ? 'Only project managers can change these board settings.'
            : 'Only the board owner can change pipeline settings.');
    }

    protected function assertCanArchiveBoard(User $user, PipelineBoard $board): void
    {
        if ($board->visibility === 'private' && ! $board->project_id) {
            if ((int) $board->created_by === (int) $user->id) {
                return;
            }

            abort(403, 'Only the board owner can archive this board.');
        }

        if ($this->moduleAccess->isBusinessOwner($user)) {
            return;
        }

        if ($board->project_id) {
            $project = Project::query()->find($board->project_id);
            if ($project && (int) $project->created_by === (int) $user->id) {
                return;
            }
            if ((int) $board->created_by === (int) $user->id) {
                return;
            }
            abort(403, 'Only the project owner can archive this board.');
        }

        if ((int) $board->created_by === (int) $user->id) {
            return;
        }

        abort(403, 'Only the board owner can archive this board.');
    }

    /** @param  array<string, mixed>  $data */
  protected function resolveAssigneeIds(array $data, User $actor, ?PipelineLead $existing = null): array
    {
        if (array_key_exists('assignee_ids', $data) && is_array($data['assignee_ids'])) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $data['assignee_ids']))));

            return $ids !== [] ? $ids : [(int) $actor->id];
        }

        if (array_key_exists('assigned_to', $data)) {
            return $data['assigned_to'] ? [(int) $data['assigned_to']] : [];
        }

        if ($existing) {
            $pivotIds = PipelineLeadAssignee::query()
                ->where('lead_id', $existing->id)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($pivotIds !== []) {
                return $pivotIds;
            }

            return $existing->assigned_to ? [(int) $existing->assigned_to] : [];
        }

        return [(int) $actor->id];
    }

    /** @param  list<int>  $userIds  @return list<int> newly added assignee user ids */
    protected function syncLeadAssignees(PipelineLead $lead, array $userIds, int $assignedBy): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $existing = PipelineLeadAssignee::query()
            ->where('lead_id', $lead->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $toAdd = array_values(array_diff($userIds, $existing));
        $toRemove = array_values(array_diff($existing, $userIds));

        if ($toRemove !== []) {
            PipelineLeadAssignee::query()
                ->where('lead_id', $lead->id)
                ->whereIn('user_id', $toRemove)
                ->delete();
        }

        foreach ($toAdd as $userId) {
            PipelineLeadAssignee::create([
                'lead_id' => $lead->id,
                'user_id' => $userId,
                'assigned_by' => $assignedBy,
            ]);
        }

        $primary = $userIds[0] ?? null;
        if ($lead->assigned_to !== $primary) {
            $lead->update(['assigned_to' => $primary]);
        }

        return $toAdd;
    }
}
