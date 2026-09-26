<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Models\User;
use App\Services\Contracts\CustomerServiceInterface;
use App\Services\ModuleAccessService;

class TopCustomersTool extends AbstractAssistantTool
{
    public function __construct(
        ModuleAccessService $moduleAccess,
        private CustomerServiceInterface $customers,
    ) {
        parent::__construct($moduleAccess);
    }

    public function name(): string
    {
        return 'top_customers';
    }

    public function description(): string
    {
        return 'Top customers by lifetime purchases. Names and totals only - no contact details.';
    }

    public function parameters(): array
    {
        return [
            'limit' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 10, 'default' => 5],
        ];
    }

    public function requiredModule(): string
    {
        return 'customers';
    }

    public function endpoint(): string
    {
        return 'GET /customers/overview';
    }

    public function execute(User $user, array $args): array
    {
        if ($denied = $this->denied($user)) {
            return $denied;
        }

        $limit = $this->argInt($args, 'limit', 5, 1, 10);
        $top = $this->customers
            ->getAll($this->businessId($user))
            ->sortByDesc(fn ($customer) => (float) $customer->total_purchases)
            ->take($limit);

        $this->audit($user, "limit={$limit}");

        return [
            'customers' => $top->map(fn ($customer) => [
                'name' => $customer->name,
                'total_purchases' => round((float) $customer->total_purchases, 2),
            ])->values()->all(),
        ];
    }
}
