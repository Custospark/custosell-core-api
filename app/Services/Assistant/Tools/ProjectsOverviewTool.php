<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Models\User;
use App\Services\Contracts\EstimateServiceInterface;
use App\Services\Contracts\ProjectServiceInterface;
use App\Services\ModuleAccessService;

class ProjectsOverviewTool extends AbstractAssistantTool
{
    public function __construct(
        ModuleAccessService $moduleAccess,
        private ProjectServiceInterface $projects,
        private EstimateServiceInterface $estimates,
    ) {
        parent::__construct($moduleAccess);
    }

    public function name(): string
    {
        return 'projects_overview';
    }

    public function description(): string
    {
        return 'Active projects plus draft and sent estimate counts. Names and statuses only.';
    }

    public function parameters(): array
    {
        return [
            'limit' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 10, 'default' => 5],
        ];
    }

    public function requiredModule(): string
    {
        return 'estimates';
    }

    public function endpoint(): string
    {
        return 'GET /projects + GET /estimates';
    }

    public function execute(User $user, array $args): array
    {
        if ($denied = $this->denied($user)) {
            return $denied;
        }

        $businessId = $this->businessId($user);
        $limit = $this->argInt($args, 'limit', 5, 1, 10);

        $projects = $this->projects->getAll($businessId)->take($limit);
        $estimates = $this->estimates->getAll($businessId);
        $byStatus = [];
        foreach ($estimates as $estimate) {
            $status = (string) ($estimate->status ?? 'unknown');
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        $this->audit($user, "limit={$limit}");

        return [
            'projects' => $projects->map(fn ($project) => [
                'name' => $project->name ?? ('Project #' . $project->id),
                'status' => $project->status ?? null,
            ])->values()->all(),
            'estimate_counts_by_status' => $byStatus,
        ];
    }
}
