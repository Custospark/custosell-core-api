<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineLabel;
use App\Models\User;
use App\Services\Pipeline\PipelineBoardLookupService;
use App\Services\Pipeline\PipelineBoardPermissionService;
use App\Services\Pipeline\PipelineBoardSeedService;
use Illuminate\Database\Eloquent\Collection;

class PipelineLabelService
{
    public function __construct(
        protected PipelineBoardLookupService $lookup,
        protected PipelineBoardPermissionService $permission,
        protected PipelineBoardSeedService $boardSeed,
    ) {}

    public function listLabels(int $businessId, User $user, ?int $boardId = null): Collection
    {
        if ($boardId) {
            $board = $this->lookup->findBoardForUser($user, $boardId);
            $this->permission->assertCanViewBoard($user, $board);
            $effectiveBusinessId = (int) $board->business_id;
        } else {
            $effectiveBusinessId = $businessId;
        }

        $labels = PipelineLabel::query()
            ->where('business_id', $effectiveBusinessId)
            ->when($boardId, fn ($q) => $q->where('board_id', $boardId))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($boardId && $labels->isEmpty()) {
            $this->seedDefaultLabels($effectiveBusinessId, $boardId);

            return PipelineLabel::query()
                ->where('business_id', $effectiveBusinessId)
                ->where('board_id', $boardId)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();
        }

        return $labels;
    }

    public function createLabel(int $businessId, User $user, array $data): PipelineLabel
    {
        if (!empty($data['board_id'])) {
            $board = $this->lookup->findBoardForUser($user, (int) $data['board_id']);
            $this->permission->assertCanEditBoard($user, $board);
            $effectiveBusinessId = (int) $board->business_id;
        } else {
            $effectiveBusinessId = $businessId;
        }

        $maxOrder = PipelineLabel::query()
            ->where('business_id', $effectiveBusinessId)
            ->where('board_id', $data['board_id'] ?? null)
            ->max('sort_order');

        return PipelineLabel::create([
            'business_id' => $effectiveBusinessId,
            'board_id' => $data['board_id'] ?? null,
            'name' => $data['name'],
            'color' => $data['color'] ?? '#6366f1',
            'sort_order' => (int) ($data['sort_order'] ?? ($maxOrder + 1)),
        ]);
    }

    public function updateLabel(int $businessId, User $user, int $labelId, array $data): PipelineLabel
    {
        $label = PipelineLabel::query()
            ->where('id', $labelId)
            ->firstOrFail();

        if ($label->board_id) {
            $board = $this->lookup->findBoardForUser($user, $label->board_id);
            $this->permission->assertCanEditBoard($user, $board);
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
            ->where('id', $labelId)
            ->firstOrFail();

        if ($label->board_id) {
            $board = $this->lookup->findBoardForUser($user, $label->board_id);
            $this->permission->assertCanEditBoard($user, $board);
        }

        $label->delete();
    }

    protected function seedDefaultLabels(int $businessId, int $boardId): void
    {
        $this->boardSeed->seedDefaultLabels($businessId, $boardId);
    }
}
