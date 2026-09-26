<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Models\User;
use App\Services\Contracts\ProductServiceInterface;
use App\Services\ModuleAccessService;

class ProductSearchTool extends AbstractAssistantTool
{
    public function __construct(
        ModuleAccessService $moduleAccess,
        private ProductServiceInterface $products,
    ) {
        parent::__construct($moduleAccess);
    }

    public function name(): string
    {
        return 'product_search';
    }

    public function description(): string
    {
        return 'Find products by name, SKU or barcode. Prices and stock included, no cost data.';
    }

    public function parameters(): array
    {
        return [
            'query' => ['type' => 'string', 'required' => true, 'max' => 120],
            'limit' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 10, 'default' => 5],
        ];
    }

    public function requiredModule(): string
    {
        return 'inventory';
    }

    public function endpoint(): string
    {
        return 'GET /products';
    }

    public function execute(User $user, array $args): array
    {
        if ($denied = $this->denied($user)) {
            return $denied;
        }

        $query = mb_strtolower($this->argString($args, 'query'));
        if ($query === '') {
            return ['error' => 'Empty search - provide a product name, SKU or barcode.'];
        }
        $limit = $this->argInt($args, 'limit', 5, 1, 10);

        $matches = $this->products->getActive($this->businessId($user))
            ->filter(fn ($product) => str_contains(mb_strtolower((string) $product->name), $query)
                || str_contains(mb_strtolower((string) $product->sku), $query)
                || str_contains(mb_strtolower((string) $product->barcode), $query))
            ->take($limit);

        $this->audit($user, "q={$query}");

        return [
            'products' => $matches->map(fn ($product) => [
                'name' => $product->name,
                'sku' => $product->sku,
                'unit_price' => round((float) $product->unit_price, 2),
                'stock' => (float) $product->stock_quantity,
            ])->values()->all(),
        ];
    }
}
