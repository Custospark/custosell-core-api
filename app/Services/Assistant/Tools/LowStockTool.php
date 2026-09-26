<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Models\User;
use App\Services\Contracts\ProductServiceInterface;
use App\Services\ModuleAccessService;

class LowStockTool extends AbstractAssistantTool
{
    public function __construct(
        ModuleAccessService $moduleAccess,
        private ProductServiceInterface $products,
    ) {
        parent::__construct($moduleAccess);
    }

    public function name(): string
    {
        return 'low_stock';
    }

    public function description(): string
    {
        return 'Products at or below their low-stock threshold. Prices included, no supplier data.';
    }

    public function parameters(): array
    {
        return [
            'limit' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 20, 'default' => 10],
        ];
    }

    public function requiredModule(): string
    {
        return 'inventory';
    }

    public function endpoint(): string
    {
        return 'GET /products/low-stock';
    }

    public function execute(User $user, array $args): array
    {
        if ($denied = $this->denied($user)) {
            return $denied;
        }

        $limit = $this->argInt($args, 'limit', 10, 1, 20);
        $products = $this->products->getLowStock($this->businessId($user))->take($limit);

        $this->audit($user, "limit={$limit}");

        return [
            'products' => $products->map(fn ($product) => [
                'name' => $product->name,
                'sku' => $product->sku,
                'stock' => (float) $product->stock_quantity,
                'threshold' => $product->low_stock_threshold,
                'unit_price' => round((float) $product->unit_price, 2),
            ])->values()->all(),
        ];
    }
}
