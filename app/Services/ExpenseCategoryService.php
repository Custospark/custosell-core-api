<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Repositories\Contracts\ExpenseCategoryRepositoryInterface;
use App\Services\Contracts\ExpenseCategoryServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpenseCategoryService implements ExpenseCategoryServiceInterface
{
    public function __construct(
        protected ExpenseCategoryRepositoryInterface $expenseCategoryRepository,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->expenseCategoryRepository->all($businessId);
    }

    public function getById(int $id): ?ExpenseCategory
    {
        return $this->expenseCategoryRepository->find($id);
    }

    public function getByIdForBusiness(int $businessId, int $id): ?ExpenseCategory
    {
        return $this->expenseCategoryRepository->findAvailableForBusiness($businessId, $id);
    }

    public function create(int $businessId, array $data): ExpenseCategory
    {
        $this->assertCustomNameAvailable($businessId, $data['name']);

        $data['business_id'] = $businessId;
        $data['slug'] = $this->uniqueSlugForBusiness($businessId, Str::slug($data['name']));

        return $this->expenseCategoryRepository->create($data);
    }

    public function update(int $businessId, int $id, array $data): ExpenseCategory
    {
        $expenseCategory = $this->expenseCategoryRepository->findAvailableForBusiness($businessId, $id);
        if (!$expenseCategory) {
            throw new \RuntimeException('Expense category not found');
        }

        $this->assertEditableCategory($expenseCategory);

        if (isset($data['name'])) {
            $this->assertCustomNameAvailable($businessId, $data['name'], $expenseCategory->id);
            $data['slug'] = $this->uniqueSlugForBusiness(
                $businessId,
                Str::slug($data['name']),
                $expenseCategory->id,
            );
        }

        return $this->expenseCategoryRepository->update($expenseCategory, $data);
    }

    public function delete(int $businessId, int $id): bool
    {
        $expenseCategory = $this->expenseCategoryRepository->findAvailableForBusiness($businessId, $id);
        if (!$expenseCategory) {
            throw new \RuntimeException('Expense category not found');
        }

        $this->assertEditableCategory($expenseCategory);

        return $this->expenseCategoryRepository->delete($expenseCategory);
    }

    protected function assertEditableCategory(ExpenseCategory $category): void
    {
        if ($this->expenseCategoryRepository->isSystemTemplate($category)) {
            throw ValidationException::withMessages([
                'expense_category' => 'System expense categories cannot be modified. Create a custom category instead.',
            ]);
        }
    }

    protected function assertCustomNameAvailable(int $businessId, string $name, ?int $ignoreId = null): void
    {
        if ($this->expenseCategoryRepository->nameExistsForBusiness($businessId, $name, $ignoreId)) {
            throw ValidationException::withMessages([
                'name' => 'An expense category with this name already exists.',
            ]);
        }
    }

    protected function uniqueSlugForBusiness(int $businessId, string $baseSlug, ?int $ignoreId = null): string
    {
        $slug = $baseSlug !== '' ? $baseSlug : 'category';
        $candidate = $slug;
        $suffix = 2;

        while ($this->slugTakenForBusiness($businessId, $candidate, $ignoreId)) {
            $candidate = $slug . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    protected function slugTakenForBusiness(int $businessId, string $slug, ?int $ignoreId = null): bool
    {
        return ExpenseCategory::query()
            ->where('slug', $slug)
            ->where(function ($query) use ($businessId) {
                $query->whereNull('business_id')
                    ->orWhere('business_id', $businessId);
            })
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
