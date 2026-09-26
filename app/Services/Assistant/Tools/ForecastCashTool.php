<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Models\User;
use App\Services\Forecasting\ForecastBudgetService;
use App\Services\Forecasting\ForecastScenarioService;
use App\Services\ModuleAccessService;

class ForecastCashTool extends AbstractAssistantTool
{
    public function __construct(
        ModuleAccessService $moduleAccess,
        private ForecastBudgetService $budgets,
        private ForecastScenarioService $scenarios,
    ) {
        parent::__construct($moduleAccess);
    }

    public function name(): string
    {
        return 'forecast_cash';
    }

    public function description(): string
    {
        return 'Budget count with the latest budget year plus scenario count. No line-item detail.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function requiredModule(): string
    {
        return 'forecasting';
    }

    public function endpoint(): string
    {
        return 'GET /forecasting/budgets';
    }

    public function execute(User $user, array $args): array
    {
        if ($denied = $this->denied($user)) {
            return $denied;
        }

        $businessId = $this->businessId($user);
        $budgets = $this->budgets->listBudgets($businessId);
        $latest = $budgets[0] ?? null;

        $this->audit($user);

        return [
            'budget_count' => count($budgets),
            'latest_budget_year' => $latest['year'] ?? null,
            'latest_budget_lines' => isset($latest['lines']) && is_array($latest['lines']) ? count($latest['lines']) : null,
            'scenario_count' => count($this->scenarios->list($businessId)),
        ];
    }
}
