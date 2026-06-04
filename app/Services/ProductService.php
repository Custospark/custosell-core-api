<?php

namespace App\Services;

use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\Contracts\ProductServiceInterface;
use Illuminate\Database\Eloquent\Collection;

class ProductService implements ProductServiceInterface
{
    public function __construct(
        protected ProductRepositoryInterface $productRepository,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->productRepository->all($businessId);
    }

    public function getById(int $id): ?Product
    {
        return $this->productRepository->find($id);
    }

    public function create(int $businessId, array $data): Product
    {
        $data['business_id'] = $businessId;
        return $this->productRepository->create($data);
    }

    public function update(int $id, array $data): Product
    {
        $product = $this->productRepository->find($id);
        if (!$product) {
            throw new \RuntimeException('Product not found');
        }
        return $this->productRepository->update($product, $data);
    }

    public function delete(int $id): bool
    {
        $product = $this->productRepository->find($id);
        if (!$product) {
            throw new \RuntimeException('Product not found');
        }
        return $this->productRepository->delete($product);
    }

    public function bulkDelete(array $ids, int $businessId): int
    {
        return \App\Models\Product::whereIn('id', $ids)
            ->where('business_id', $businessId)
            ->delete();
    }

    public function getActive(int $businessId): Collection
    {
        return $this->productRepository->getActive($businessId);
    }

    public function getLowStock(int $businessId): Collection
    {
        return $this->productRepository->getLowStock($businessId);
    }
}
