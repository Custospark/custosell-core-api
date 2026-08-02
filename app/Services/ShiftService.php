<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Shift;
use App\Models\User;
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
        $data['location_id'] = $this->resolveLocationId($businessId, $userId, $data['location_id'] ?? null);
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

    protected function resolveLocationId(int $businessId, int $userId, ?int $locationId): ?int
    {
        if ($locationId) {
            $exists = Location::forBusiness($businessId)->where('id', $locationId)->exists();
            if ($exists) {
                return $locationId;
            }
        }

        $userLocation = User::query()
            ->where('id', $userId)
            ->where('business_id', $businessId)
            ->value('location_id');

        if ($userLocation && Location::forBusiness($businessId)->where('id', $userLocation)->exists()) {
            return $userLocation;
        }

        return Location::forBusiness($businessId)->where('is_default', true)->value('id');
    }
}
