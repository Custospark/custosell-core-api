<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Models\User;
use App\Services\Contracts\SaleServiceInterface;
use App\Services\ModuleAccessService;

class SalesHistoryTool extends AbstractAssistantTool
{
    public function __construct(
        ModuleAccessService $moduleAccess,
        private SaleServiceInterface $sales,
    ) {
        parent::__construct($moduleAccess);
    }

    public function name(): string
    {
        return 'sales_history';
    }

    public function description(): string
    {
        return 'Recent sales receipts, newest first. No customer contact details.';
    }

    public function parameters(): array
    {
        return [
            'limit' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 10, 'default' => 5],
            'days_ago' => ['type' => 'int', 'required' => false, 'min' => 0, 'max' => 30, 'default' => 0],
        ];
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

        $limit = $this->argInt($args, 'limit', 5, 1, 10);
        $date = now()->subDays($this->argInt($args, 'days_ago', 0, 0, 30))->toDateString();
        $sales = $this->sales->getDaily($this->businessId($user), $date)->take($limit);

        $this->audit($user, "date={$date} limit={$limit}");

        return [
            'date' => $date,
            'receipts' => $sales->map(fn ($sale) => [
                'receipt' => $sale->receipt_number,
                'total' => round((float) $sale->total_amount, 2),
                'items' => $sale->saleItems?->count() ?? 0,
            ])->values()->all(),
        ];
    }
}
