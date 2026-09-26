<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Models\User;
use App\Services\Contracts\SaleServiceInterface;
use App\Services\ModuleAccessService;

class SalesTodayTool extends AbstractAssistantTool
{
    public function __construct(
        ModuleAccessService $moduleAccess,
        private SaleServiceInterface $sales,
    ) {
        parent::__construct($moduleAccess);
    }

    public function name(): string
    {
        return 'sales_today';
    }

    public function description(): string
    {
        return 'Today\'s sales totals: receipt count, gross total, tax total.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function requiredModule(): string
    {
        return 'sales';
    }

    public function endpoint(): string
    {
        return 'GET /sales/daily';
    }

    public function execute(User $user, array $args): array
    {
        if ($denied = $this->denied($user)) {
            return $denied;
        }

        $sales = $this->sales->getDaily($this->businessId($user));
        $total = 0.0;
        $tax = 0.0;
        foreach ($sales as $sale) {
            $total += (float) $sale->total_amount;
            $tax += (float) $sale->tax_total;
        }

        $this->audit($user, "count={$sales->count()}");

        return [
            'date' => now()->toDateString(),
            'receipt_count' => $sales->count(),
            'total' => round($total, 2),
            'tax_total' => round($tax, 2),
        ];
    }
}
