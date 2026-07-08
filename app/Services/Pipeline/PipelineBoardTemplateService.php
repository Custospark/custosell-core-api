<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardTemplate;
use App\Models\PipelineLabel;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\PipelineService;

class PipelineBoardTemplateService
{
    public function __construct(
        protected PipelineService $pipeline,
        protected PipelineBoardResourceService $resources,
        protected PipelineBoardAutomationService $automations,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listTemplates(int $businessId, User $user, string $workspace = 'pipeline'): array
    {
        return PipelineBoardTemplate::query()
            ->where('business_id', $businessId)
            ->where('workspace', $workspace === 'estimates' ? 'estimates' : 'pipeline')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->map(fn (PipelineBoardTemplate $template) => $this->serializeTemplate($template))
            ->all();
    }

    /** @return array<string, mixed> */
    public function createTemplate(
        int $businessId,
        User $user,
        string $name,
        string $workspace,
        ?string $description = null,
        ?array $stages = null,
        ?array $labels = null,
        ?array $resourceSeeds = null,
        ?array $automationSeeds = null,
    ): array {
        $template = PipelineBoardTemplate::create([
            'business_id' => $businessId,
            'created_by' => $user->id,
            'name' => $name,
            'description' => $description,
            'workspace' => $workspace === 'estimates' ? 'estimates' : 'pipeline',
            'stages' => $stages,
            'labels' => $labels,
            'resources' => $resourceSeeds,
            'automations' => $automationSeeds,
            'is_system' => false,
        ]);

        return $this->serializeTemplate($template);
    }

    public function applyTemplate(int $businessId, User $user, int $boardId, int $templateId): void
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);
        $this->pipeline->userCanManageBoard($user, $board) || abort(403);

        $template = PipelineBoardTemplate::query()
            ->where('business_id', $businessId)
            ->whereKey($templateId)
            ->firstOrFail();

        foreach ($template->stages ?? [] as $index => $stage) {
            if (! is_array($stage) || empty($stage['name'])) {
                continue;
            }
            $exists = PipelineStage::query()
                ->where('board_id', $board->id)
                ->where('name', $stage['name'])
                ->exists();
            if ($exists) {
                continue;
            }
            PipelineStage::create([
                'board_id' => $board->id,
                'name' => $stage['name'],
                'sort_order' => $index,
                'color' => $stage['color'] ?? null,
                'is_won' => (bool) ($stage['is_won'] ?? false),
                'is_lost' => (bool) ($stage['is_lost'] ?? false),
            ]);
        }

        foreach ($template->labels ?? [] as $index => $label) {
            if (! is_array($label) || empty($label['name'])) {
                continue;
            }
            $exists = PipelineLabel::query()
                ->where('board_id', $board->id)
                ->where('name', $label['name'])
                ->exists();
            if ($exists) {
                continue;
            }
            PipelineLabel::create([
                'business_id' => $businessId,
                'board_id' => $board->id,
                'name' => $label['name'],
                'color' => $label['color'] ?? '#64748b',
                'sort_order' => $index,
            ]);
        }

        foreach ($template->resources ?? [] as $seed) {
            if (! is_array($seed) || empty($seed['title'])) {
                continue;
            }
            if (! empty($seed['url'])) {
                $this->resources->createLinkResource(
                    $businessId,
                    $user,
                    $boardId,
                    $seed['title'],
                    $seed['url'],
                    $seed['visibility'] ?? 'board',
                    $seed['description'] ?? null,
                    $seed['group_name'] ?? null,
                );
            }
        }

        foreach ($template->automations ?? [] as $seed) {
            if (! is_array($seed) || empty($seed['name']) || empty($seed['action_body'])) {
                continue;
            }
            $this->automations->createAutomation(
                $businessId,
                $user,
                $boardId,
                $seed['name'],
                $seed['trigger_type'] ?? 'stage_entered',
                $seed['action_type'] ?? 'conversation_post',
                $seed['action_body'],
                isset($seed['trigger_stage_id']) ? (int) $seed['trigger_stage_id'] : null,
            );
        }
    }

    /** @return array<string, mixed> */
    protected function serializeTemplate(PipelineBoardTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'workspace' => $template->workspace,
            'stages' => $template->stages ?? [],
            'labels' => $template->labels ?? [],
            'resources' => $template->resources ?? [],
            'automations' => $template->automations ?? [],
            'is_system' => $template->is_system,
        ];
    }
}
