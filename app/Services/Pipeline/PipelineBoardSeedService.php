<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineLabel;
use App\Models\PipelineLead;
use App\Models\PipelineStage;
use App\Services\PipelineService;

class PipelineBoardSeedService
{
    /** @var list<array{cover_color: string, background_value: string}> */
    public const GALLERY_PRESETS = [
        ['cover_color' => '#6366f1', 'background_value' => 'https://picsum.photos/id/10/1200/800'],
        ['cover_color' => '#8b5cf6', 'background_value' => 'https://picsum.photos/id/15/1200/800'],
        ['cover_color' => '#059669', 'background_value' => 'https://picsum.photos/id/26/1200/800'],
        ['cover_color' => '#dc2626', 'background_value' => 'https://picsum.photos/id/28/1200/800'],
        ['cover_color' => '#ea580c', 'background_value' => 'https://picsum.photos/id/36/1200/800'],
        ['cover_color' => '#0284c7', 'background_value' => 'https://picsum.photos/id/40/1200/800'],
    ];

    /** @return array{cover_color: string, background_type: 'gallery', background_value: string} */
    public function defaultAppearance(int $seedIndex = 0): array
    {
        $preset = self::GALLERY_PRESETS[$seedIndex % count(self::GALLERY_PRESETS)];

        return [
            'cover_color' => $preset['cover_color'],
            'background_type' => 'gallery',
            'background_value' => $preset['background_value'],
        ];
    }

    public function applyDefaultAppearance(PipelineBoard $board, int $seedIndex = 0): void
    {
        $appearance = $this->defaultAppearance($seedIndex);
        $dirty = false;

        $backgroundValue = is_string($board->background_value) ? trim($board->background_value) : '';
        $backgroundType = is_string($board->background_type) ? trim($board->background_type) : '';
        $hasImageBackground = $backgroundValue !== ''
            && in_array($backgroundType, ['gallery', 'upload'], true);

        if (! $hasImageBackground) {
            $board->background_type = $appearance['background_type'];
            $board->background_value = $appearance['background_value'];
            $dirty = true;
        }

        $coverColor = is_string($board->cover_color) ? trim($board->cover_color) : '';
        if ($coverColor === '') {
            $board->cover_color = $appearance['cover_color'];
            $dirty = true;
        }

        if ($dirty) {
            $board->save();
        }
    }

    public function seedDefaultLabels(int $businessId, int $boardId): void
    {
        foreach (PipelineService::DEFAULT_LABELS as $index => $label) {
            PipelineLabel::create([
                'business_id' => $businessId,
                'board_id' => $boardId,
                'name' => $label['name'],
                'color' => $label['color'],
                'sort_order' => $index,
            ]);
        }
    }

    public function seedGuidingCards(PipelineBoard $board, int $userId): void
    {
        $hasLeads = PipelineLead::query()
            ->where('board_id', $board->id)
            ->exists();

        if ($hasLeads) {
            return;
        }

        $isProjectWorkspace = $board->project_id !== null
            || $board->workspace === 'estimates';

        $guides = $isProjectWorkspace
            ? $this->projectGuidingCards()
            : $this->pipelineGuidingCards();

        $cardType = $isProjectWorkspace ? 'card' : 'lead';
        $stagesByName = PipelineStage::query()
            ->where('board_id', $board->id)
            ->get()
            ->keyBy(fn (PipelineStage $stage) => mb_strtolower(trim((string) $stage->name)));

        $position = 1;
        foreach ($guides as $guide) {
            $stage = $stagesByName->get(mb_strtolower($guide['stage']));
            if (! $stage) {
                continue;
            }

            PipelineLead::create([
                'business_id' => $board->business_id,
                'board_id' => $board->id,
                'stage_id' => $stage->id,
                'created_by' => $userId,
                'assigned_to' => $userId,
                'title' => $guide['title'],
                'card_type' => $cardType,
                'description' => $guide['description'],
                'currency' => 'UGX',
                'status' => 'open',
                'position' => $position,
            ]);

            $position++;
        }
    }

    /** @return list<array{stage: string, title: string, description: string}> */
    protected function pipelineGuidingCards(): array
    {
        return [
            [
                'stage' => 'New',
                'title' => 'Welcome to your sales pipeline',
                'description' => 'Move deals across stages as they progress. Drag cards from left to right as you qualify, propose, and close.',
            ],
            [
                'stage' => 'New',
                'title' => 'Add your first real lead',
                'description' => 'Replace these guide cards with real opportunities. Capture contact details, value, and next steps.',
            ],
            [
                'stage' => 'Contacted',
                'title' => 'Log the next follow-up',
                'description' => 'Use notes and activity on each card to track calls, emails, and follow-ups so nothing slips.',
            ],
        ];
    }

    /** @return list<array{stage: string, title: string, description: string}> */
    protected function projectGuidingCards(): array
    {
        return [
            [
                'stage' => 'To Do',
                'title' => 'Break work into cards',
                'description' => 'Create a card for each piece of work so the board shows what still needs to happen.',
            ],
            [
                'stage' => 'To Do',
                'title' => 'Assign owners and due dates',
                'description' => 'Put a name and due date on cards so everyone knows who owns what and when it is due.',
            ],
            [
                'stage' => 'In Progress',
                'title' => 'Move cards as work progresses',
                'description' => 'Drag cards from To Do into In Progress and Done so the board stays an accurate picture of status.',
            ],
        ];
    }
}
