<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\PipelineBoard;
use App\Models\PipelineBoardMember;
use App\Models\PipelineLead;
use App\Models\PipelineLeadActivity;
use App\Models\PipelineSource;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PipelineService
{
    public function __construct(
        protected ModuleAccessService $moduleAccess,
        protected CustomerContactService $customerContactService,
    ) {}

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

    /** @param  array<string, mixed>  $data */
    public function createBoard(int $businessId, int $userId, array $data): PipelineBoard
    {
        return DB::transaction(function () use ($businessId, $userId, $data) {
            $board = PipelineBoard::create([
                'business_id' => $businessId,
                'created_by' => $userId,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'visibility' => $data['visibility'] ?? 'team',
                'cover_color' => $data['cover_color'] ?? null,
                'is_default' => (bool) ($data['is_default'] ?? false),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);

            if (!empty($data['member_ids']) && $board->visibility === 'shared') {
                $this->syncBoardMembers($board, $data['member_ids']);
            }

            foreach (self::DEFAULT_STAGES as $index => $stage) {
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

            return $board->load(['stages', 'members.user', 'creator']);
        });
    }

    /** @param  list<int>  $memberIds */
    public function syncBoardMembers(PipelineBoard $board, array $memberIds): void
    {
        PipelineBoardMember::query()->where('board_id', $board->id)->delete();

        foreach (array_unique($memberIds) as $memberId) {
            if ((int) $memberId === (int) $board->created_by) {
                continue;
            }
            PipelineBoardMember::create([
                'board_id' => $board->id,
                'user_id' => (int) $memberId,
                'role' => 'editor',
            ]);
        }
    }

    public function listBoards(int $businessId, User $user): Collection
    {
        $this->ensureBusinessSetup($businessId, $user->id);

        return PipelineBoard::query()
            ->where('business_id', $businessId)
            ->where('is_archived', false)
            ->withCount(['leads as open_leads_count' => fn ($q) => $q->where('status', 'open')])
            ->with(['creator:id,name'])
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
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
        $this->assertCanManageBoard($user, $board);

        $board->update(array_filter([
            'name' => $data['name'] ?? null,
            'description' => array_key_exists('description', $data) ? $data['description'] : $board->description,
            'visibility' => $data['visibility'] ?? null,
            'cover_color' => array_key_exists('cover_color', $data) ? $data['cover_color'] : $board->cover_color,
            'is_archived' => array_key_exists('is_archived', $data) ? $data['is_archived'] : null,
        ], fn ($v) => $v !== null));

        if ($board->visibility === 'shared' && array_key_exists('member_ids', $data)) {
            $this->syncBoardMembers($board, $data['member_ids'] ?? []);
        }

        return $board->fresh(['stages', 'members.user', 'creator']);
    }

    public function getKanban(int $businessId, User $user, int $boardId): PipelineBoard
    {
        $board = $this->findBoardForBusiness($businessId, $boardId);
        $this->assertCanViewBoard($user, $board);

        return $board->load([
            'stages.leads' => fn ($q) => $q
                ->whereIn('status', ['open', 'won', 'lost'])
                ->with(['assignee:id,name', 'source:id,name', 'customer:id,name,email,phone'])
                ->orderBy('position'),
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
        $this->assertCanManageBoard($user, $board);

        foreach ($stageIdsInOrder as $order => $stageId) {
            PipelineStage::query()
                ->where('board_id', $boardId)
                ->where('business_id', $businessId)
                ->where('id', $stageId)
                ->update(['sort_order' => $order]);
        }

        return $board->stages()->orderBy('sort_order')->get();
    }

    /** @param  array<string, mixed>  $filters */
    public function listLeads(int $businessId, User $user, array $filters = []): Collection
    {
        $this->ensureBusinessSetup($businessId, $user->id);

        $query = PipelineLead::query()
            ->where('business_id', $businessId)
            ->with(['board', 'stage', 'assignee:id,name', 'source:id,name', 'customer:id,name,email,phone']);

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
                $query->where('assigned_to', $user->id);
            } else {
                $query->where('assigned_to', (int) $filters['assigned_to']);
            }
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

        $lead = PipelineLead::create([
            'business_id' => $businessId,
            'board_id' => $board->id,
            'stage_id' => $stage->id,
            'created_by' => $user->id,
            'assigned_to' => $data['assigned_to'] ?? $user->id,
            'customer_id' => $data['customer_id'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'estimated_value' => $data['estimated_value'] ?? null,
            'currency' => $data['currency'] ?? 'UGX',
            'status' => 'open',
            'position' => ($maxPosition ?? 0) + 1,
            'expected_close_date' => $data['expected_close_date'] ?? null,
        ]);

        $this->recordActivity($lead, $user->id, 'system', 'Lead created');

        return $lead->load(['board', 'stage', 'assignee:id,name', 'source:id,name', 'customer:id,name,email,phone']);
    }

    public function getLead(int $businessId, User $user, int $leadId): PipelineLead
    {
        $lead = $this->findLeadForBusiness($businessId, $leadId);
        $this->assertCanViewBoard($user, $lead->board);

        return $lead->load(['board', 'stage', 'assignee:id,name', 'source:id,name', 'customer:id,name,email,phone', 'activities.user:id,name']);
    }

    /** @param  array<string, mixed>  $data */
    public function updateLead(int $businessId, User $user, int $leadId, array $data): PipelineLead
    {
        $lead = $this->findLeadForBusiness($businessId, $leadId);
        $this->assertCanEditBoard($user, $lead->board);

        $updates = [];
        foreach ([
            'title', 'description', 'assigned_to', 'customer_id', 'source_id',
            'contact_name', 'contact_email', 'contact_phone', 'estimated_value',
            'currency', 'expected_close_date', 'lost_reason',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if ($updates !== []) {
            $lead->update($updates);
        }

        return $lead->fresh(['board', 'stage', 'assignee:id,name', 'source:id,name', 'customer:id,name,email,phone']);
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

        if ($fromStageId !== $stage->id) {
            $this->recordActivity($lead, $user->id, 'stage_change', "Moved to {$stage->name}", [
                'from_stage_id' => $fromStageId,
                'to_stage_id' => $stage->id,
            ]);
        }

        return $lead->fresh(['board', 'stage', 'assignee:id,name', 'source:id,name', 'customer:id,name,email,phone']);
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

        return $lead->fresh(['board', 'stage', 'assignee:id,name', 'source:id,name', 'customer:id,name,email,phone', 'convertedCustomer:id,name,email,phone']);
    }

    public function addActivity(int $businessId, User $user, int $leadId, string $type, ?string $body, ?array $metadata = null): PipelineLeadActivity
    {
        $lead = $this->findLeadForBusiness($businessId, $leadId);
        $this->assertCanEditBoard($user, $lead->board);

        return $this->recordActivity($lead, $user->id, $type, $body, $metadata);
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
            : $this->listBoards($businessId, $user);

        $boardIds = $boards->pluck('id');

        $leads = PipelineLead::query()
            ->where('business_id', $businessId)
            ->whereIn('board_id', $boardIds)
            ->whereIn('status', ['open', 'won', 'lost'])
            ->with('stage:id,name,is_won,is_lost,color')
            ->get();

        $openLeads = $leads->where('status', 'open');
        $wonLeads = $leads->where('status', 'won');
        $lostLeads = $leads->where('status', 'lost');

        $byStage = $openLeads->groupBy('stage_id')->map(function ($group, $stageId) {
            $stage = $group->first()->stage;

            return [
                'stage_id' => (int) $stageId,
                'stage_name' => $stage?->name ?? 'Unknown',
                'color' => $stage?->color,
                'count' => $group->count(),
                'value' => round((float) $group->sum('estimated_value'), 2),
            ];
        })->values();

        $totalOpen = $openLeads->count();
        $closed = $wonLeads->count() + $lostLeads->count();
        $winRate = $closed > 0 ? round(($wonLeads->count() / $closed) * 100, 1) : 0;

        return [
            'open_leads' => $totalOpen,
            'open_pipeline_value' => round((float) $openLeads->sum('estimated_value'), 2),
            'won_leads' => $wonLeads->count(),
            'lost_leads' => $lostLeads->count(),
            'win_rate_percent' => $winRate,
            'by_stage' => $byStage,
        ];
    }

    protected function recordActivity(PipelineLead $lead, ?int $userId, string $type, ?string $body, ?array $metadata = null): PipelineLeadActivity
    {
        return PipelineLeadActivity::create([
            'business_id' => $lead->business_id,
            'lead_id' => $lead->id,
            'user_id' => $userId,
            'type' => $type,
            'body' => $body,
            'metadata' => $metadata,
        ]);
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
        if ($this->moduleAccess->isBusinessOwner($user)) {
            return true;
        }

        return match ($board->visibility) {
            'team' => $this->moduleAccess->canAccess($user, 'pipeline'),
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

    protected function assertCanEditBoard(User $user, PipelineBoard $board): void
    {
        $this->assertCanViewBoard($user, $board);

        if ($this->moduleAccess->isBusinessOwner($user) || (int) $board->created_by === (int) $user->id) {
            return;
        }

        if ($board->visibility === 'team') {
            return;
        }

        if ($board->visibility === 'shared') {
            $member = PipelineBoardMember::query()
                ->where('board_id', $board->id)
                ->where('user_id', $user->id)
                ->first();

            if ($member && $member->role === 'editor') {
                return;
            }
        }

        abort(403, 'You cannot edit this pipeline board.');
    }

    protected function assertCanManageBoard(User $user, PipelineBoard $board): void
    {
        if ($this->moduleAccess->isBusinessOwner($user) || (int) $board->created_by === (int) $user->id) {
            return;
        }

        abort(403, 'Only the board owner can change pipeline settings.');
    }
}
