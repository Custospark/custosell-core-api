<?php

namespace App\Services;

use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Services\Contracts\CategoryServiceInterface;
use Illuminate\Database\Eloquent\Collection;

class CategoryService implements CategoryServiceInterface
{
    public function __construct(
        protected CategoryRepositoryInterface $categoryRepository,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->categoryRepository->all($businessId);
    }

    public function getById(int $id): ?Category
    {
        return $this->categoryRepository->find($id);
    }

    public function create(int $businessId, array $data): Category
    {
        $data['business_id'] = $businessId;
        return $this->categoryRepository->create($data);
    }

    public function update(int $id, array $data): Category
    {
        $category = $this->categoryRepository->find($id);
        if (!$category) {
            throw new \RuntimeException('Category not found');
        }
        return $this->categoryRepository->update($category, $data);
    }

    public function delete(int $id): bool
    {
        $category = $this->categoryRepository->find($id);
        if (!$category) {
            throw new \RuntimeException('Category not found');
        }
        return $this->categoryRepository->delete($category);
    }
}
