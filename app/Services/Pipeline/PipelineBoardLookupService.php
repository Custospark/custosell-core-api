<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardMember;
use App\Models\PipelineLead;
use App\Models\PipelineStage;
use App\Models\User;

class PipelineBoardLookupService
{
    /**
     * Boards are addressed by an opaque `code` in client URLs, but legacy
     * numeric ids still work so existing links and internal callers (stages,
     * leads, resources) keep resolving.
     */
    protected function scopeByBoardReference(\Illuminate\Database\Eloquent\Builder $query, string $boardRef): \Illuminate\Database\Eloquent\Builder
    {
        return ctype_digit((string) $boardRef)
            ? $query->where('id', (int) $boardRef)
            : $query->where('code', $boardRef);
    }

    public function findBoardForBusiness(int $businessId, int|string $boardRef): PipelineBoard
    {
        return PipelineBoard::query()
            ->where('business_id', $businessId)
            ->where(fn ($q) => $this->scopeByBoardReference($q, (string) $boardRef))
            ->firstOrFail();
    }

    public function findStageForBusiness(int $businessId, int $stageId): PipelineStage
    {
        return PipelineStage::query()
            ->where('business_id', $businessId)
            ->where('id', $stageId)
            ->with('board')
            ->firstOrFail();
    }

    public function findLeadForBusiness(int $businessId, int $leadId): PipelineLead
    {
        return PipelineLead::query()
            ->where('business_id', $businessId)
            ->where('id', $leadId)
            ->with('board')
            ->firstOrFail();
    }

    public function findBoardForUser(User $user, int|string $boardRef): PipelineBoard
    {
        $board = PipelineBoard::query()
            ->where('business_id', $user->business_id)
            ->where(fn ($q) => $this->scopeByBoardReference($q, (string) $boardRef))
            ->first();

        if ($board) {
            return $board;
        }

        return PipelineBoard::query()
            ->where(fn ($q) => $this->scopeByBoardReference($q, (string) $boardRef))
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->id))
            ->firstOrFail();
    }

    public function findLeadForUser(User $user, int $leadId): PipelineLead
    {
        $lead = PipelineLead::with('board')->findOrFail($leadId);

        if ((int) $lead->business_id === (int) $user->business_id) {
            return $lead;
        }

        $board = $lead->board;
        if (!$board || !$board->members()->where('user_id', $user->id)->exists()) {
            abort(404);
        }

        return $lead;
    }

    public function findStageForUser(User $user, int $stageId): PipelineStage
    {
        $stage = PipelineStage::with('board')->findOrFail($stageId);

        if ((int) $stage->business_id === (int) $user->business_id) {
            return $stage;
        }

        $board = $stage->board;
        if (!$board || !$board->members()->where('user_id', $user->id)->exists()) {
            abort(404);
        }

        return $stage;
    }
}
