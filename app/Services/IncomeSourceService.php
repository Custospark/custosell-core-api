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
        $data = $this->applyRecurrenceDefaults($data);
        return $this->incomeSourceRepository->create($data);
    }

    public function update(int $id, array $data): IncomeSource
    {
        $incomeSource = $this->incomeSourceRepository->find($id);
        if (!$incomeSource) {
            throw new \RuntimeException('Income source not found');
        }
        $data = $this->applyRecurrenceDefaults($data);
        return $this->incomeSourceRepository->update($incomeSource, $data);
    }

    protected function applyRecurrenceDefaults(array $data): array
    {
        $recurring = (bool) ($data['is_recurring'] ?? false);
        if ($recurring && empty($data['next_due_date'])) {
            $data['next_due_date'] = match ($data['recurrence_interval'] ?? 'monthly') {
                'daily' => now()->addDay()->toDateString(),
                'weekly' => now()->addWeek()->toDateString(),
                'yearly' => now()->addYear()->toDateString(),
                default => now()->addMonth()->toDateString(),
            };
        }
        if (!$recurring) {
            $data['recurrence_interval'] = null;
            $data['next_due_date'] = null;
        }
        return $data;
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
