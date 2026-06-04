<?php

namespace App\Repositories\Eloquent;

use App\Models\Shift;
use App\Repositories\Contracts\ShiftRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ShiftRepository implements ShiftRepositoryInterface
{
    public function all(int $businessId): Collection
    {
        return Shift::where('business_id', $businessId)
            ->with('user')
            ->orderBy('clock_in', 'desc')
            ->get();
    }

    public function find(int $id): ?Shift
    {
        return Shift::with('user')->find($id);
    }

    public function create(array $data): Shift
    {
        return Shift::create($data);
    }

    public function update(Shift $shift, array $data): Shift
    {
        $shift->update($data);
        return $shift->fresh();
    }

    public function delete(Shift $shift): bool
    {
        return $shift->delete();
    }

    public function getActiveByUser(int $businessId, int $userId): ?Shift
    {
        return Shift::where('business_id', $businessId)
            ->where('user_id', $userId)
            ->whereNull('clock_out')
            ->where('status', 'active')
            ->first();
    }

    public function getByDateRange(int $businessId, string $start, string $end): Collection
    {
        return Shift::where('business_id', $businessId)
            ->whereBetween('clock_in', [$start, $end])
            ->with('user')
            ->orderBy('clock_in', 'desc')
            ->get();
    }
}
