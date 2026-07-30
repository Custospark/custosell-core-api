<?php

namespace App\Services;

use App\Models\IncomeSource;
use App\Repositories\Contracts\IncomeSourceRepositoryInterface;
use App\Services\Contracts\IncomeSourceServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class IncomeSourceService implements IncomeSourceServiceInterface
{
    public function __construct(
        protected IncomeSourceRepositoryInterface $incomeSourceRepository,
    ) {}

    public function getAll(int $businessId, array $filters = []): LengthAwarePaginator
    {
        return $this->incomeSourceRepository->all($businessId, $filters);
    }

    public function getById(int $id): ?IncomeSource
    {
        return $this->incomeSourceRepository->find($id);
    }

    public function create(int $businessId, int $userId, array $data): IncomeSource
    {
        $data['business_id'] = $businessId;
        $data['user_id'] = $userId;
        return $this->incomeSourceRepository->create($data);
    }

    public function update(int $id, array $data): IncomeSource
    {
        $incomeSource = $this->incomeSourceRepository->find($id);
        if (!$incomeSource) {
            throw new \RuntimeException('Income source not found');
        }
        return $this->incomeSourceRepository->update($incomeSource, $data);
    }

    public function delete(int $id): bool
    {
        $incomeSource = $this->incomeSourceRepository->find($id);
        if (!$incomeSource) {
            throw new \RuntimeException('Income source not found');
        }
        return $this->incomeSourceRepository->delete($incomeSource);
    }

    public function getByDateRange(int $businessId, string $start, string $end): Collection
    {
        return $this->incomeSourceRepository->getByDateRange($businessId, $start, $end);
    }

    public function getSummary(int $businessId, array $filters = []): array
    {
        return $this->incomeSourceRepository->getSummary($businessId, $filters);
    }
}
