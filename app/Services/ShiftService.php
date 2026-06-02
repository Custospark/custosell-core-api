<?php

namespace App\Services;

use App\Models\Shift;
use App\Repositories\Contracts\ShiftRepositoryInterface;
use App\Services\Contracts\ShiftServiceInterface;
use Illuminate\Database\Eloquent\Collection;

class ShiftService implements ShiftServiceInterface
{
    public function __construct(
        protected ShiftRepositoryInterface $shiftRepository,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->shiftRepository->all($businessId);
    }

    public function getById(int $id): ?Shift
    {
        return $this->shiftRepository->find($id);
    }

    public function create(int $businessId, int $userId, array $data): Shift
    {
        $data['business_id'] = $businessId;
        $data['user_id'] = $userId;
        return $this->shiftRepository->create($data);
    }

    public function update(int $id, array $data): Shift
    {
        $shift = $this->shiftRepository->find($id);
        if (!$shift) {
            throw new \RuntimeException('Shift not found');
        }
        return $this->shiftRepository->update($shift, $data);
    }

    public function delete(int $id): bool
    {
        $shift = $this->shiftRepository->find($id);
        if (!$shift) {
            throw new \RuntimeException('Shift not found');
        }
        return $this->shiftRepository->delete($shift);
    }

    public function getActiveByUser(int $businessId, int $userId): ?Shift
    {
        return $this->shiftRepository->getActiveByUser($businessId, $userId);
    }

    public function getByDateRange(int $businessId, string $start, string $end): Collection
    {
        return $this->shiftRepository->getByDateRange($businessId, $start, $end);
    }
}
