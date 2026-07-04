<?php

namespace App\Repositories\Eloquent;

use App\Models\AccountingPeriod;
use App\Repositories\Contracts\AccountingPeriodRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class AccountingPeriodRepository implements AccountingPeriodRepositoryInterface
{
    public function all(int $businessId): Collection
    {
        return AccountingPeriod::where('business_id', $businessId)
            ->orderBy('start_date', 'desc')
            ->get();
    }

    public function find(int $id): ?AccountingPeriod
    {
        return AccountingPeriod::with(['closedBy'])->find($id);
    }

    public function getCurrentPeriod(int $businessId): ?AccountingPeriod
    {
        return AccountingPeriod::where('business_id', $businessId)
            ->where('is_closed', false)
            ->where('start_date', '<=', now()->format('Y-m-d'))
            ->where('end_date', '>=', now()->format('Y-m-d'))
            ->first();
    }

    public function getPeriodByDate(int $businessId, string $date): ?AccountingPeriod
    {
        return AccountingPeriod::where('business_id', $businessId)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->first();
    }

    public function create(array $data): AccountingPeriod
    {
        return AccountingPeriod::create($data);
    }

    public function update(AccountingPeriod $period, array $data): AccountingPeriod
    {
        $period->update($data);
        return $period->fresh();
    }

    public function close(int $id, int $userId): AccountingPeriod
    {
        $period = $this->find($id);
        $period->update([
            'is_closed' => true,
            'closed_by' => $userId,
            'closed_at' => now(),
        ]);
        return $period->fresh();
    }

    public function reopen(int $id, int $userId): AccountingPeriod
    {
        $period = $this->find($id);
        $period->update([
            'is_closed' => false,
            'closed_by' => null,
            'closed_at' => null,
        ]);
        return $period->fresh();
    }

    public function findOrCreatePeriod(int $businessId, string $date): AccountingPeriod
    {
        $existing = $this->getPeriodByDate($businessId, $date);
        if ($existing) {
            return $existing;
        }

        $dateObj = \Carbon\Carbon::parse($date);
        $start = $dateObj->copy()->startOfMonth()->format('Y-m-d');
        $end = $dateObj->copy()->endOfMonth()->format('Y-m-d');
        $name = $dateObj->format('F Y');

        return $this->create([
            'business_id' => $businessId,
            'name' => $name,
            'start_date' => $start,
            'end_date' => $end,
            'is_closed' => false,
        ]);
    }
}
