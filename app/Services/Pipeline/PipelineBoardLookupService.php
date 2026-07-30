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
    public function findBoardForBusiness(int $businessId, int $boardId): PipelineBoard
    {
        return PipelineBoard::query()
            ->where('business_id', $businessId)
            ->where('id', $boardId)
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

    public function findBoardForUser(User $user, int $boardId): PipelineBoard
    {
        $board = PipelineBoard::query()
            ->where('business_id', $user->business_id)
            ->where('id', $boardId)
            ->first();

        if ($board) {
            return $board;
        }

        return PipelineBoard::query()
            ->where('id', $boardId)
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
