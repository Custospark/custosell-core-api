<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Models\PipelineBoard;
use App\Models\PipelineLead;
use App\Models\User;
use App\Services\ModuleAccessService;

class PipelineDealsTool extends AbstractAssistantTool
{
    public function __construct(ModuleAccessService $moduleAccess)
    {
        parent::__construct($moduleAccess);
    }

    public function name(): string
    {
        return 'pipeline_deals';
    }

    public function description(): string
    {
        return 'Sales boards with open lead counts plus the most recently touched leads. Titles only.';
    }

    public function parameters(): array
    {
        return [
            'limit' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 10, 'default' => 5],
        ];
    }

    public function requiredModule(): string
    {
        return 'pipeline';
    }

    public function endpoint(): string
    {
        return 'GET /pipeline/boards';
    }

    public function execute(User $user, array $args): array
    {
        if ($denied = $this->denied($user)) {
            return $denied;
        }

        $businessId = $this->businessId($user);
        $limit = $this->argInt($args, 'limit', 5, 1, 10);

        $boards = PipelineBoard::where('business_id', $businessId)
            ->get(['id', 'name'])
            ->map(fn ($board) => [
                'name' => $board->name,
                'open_leads' => PipelineLead::where('business_id', $businessId)
                    ->where('board_id', $board->id)
                    ->count(),
            ])
            ->values()
            ->all();

        $recent = PipelineLead::where('business_id', $businessId)
            ->latest('updated_at')
            ->take($limit)
            ->get(['title', 'board_id', 'updated_at'])
            ->map(fn ($lead) => [
                'title' => $lead->title,
            ])
            ->values()
            ->all();

        $this->audit($user, "limit={$limit}");

        return ['boards' => $boards, 'recent_leads' => $recent];
    }
}
