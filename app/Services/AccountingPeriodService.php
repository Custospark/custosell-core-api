<?php

namespace App\Services;

use App\Models\Business;
use App\Repositories\Contracts\AccountingPeriodRepositoryInterface;
use App\Repositories\Contracts\GeneralLedgerRepositoryInterface;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class AccountingPeriodService
{
    public function __construct(
        protected AccountingPeriodRepositoryInterface $accountingPeriodRepository,
        protected GeneralLedgerRepositoryInterface $generalLedgerRepository,
        protected JournalEntryRepositoryInterface $journalEntryRepository,
    ) {}

    public function getAll(int $businessId): Collection
    {
        $this->ensurePeriodsForBusiness($businessId);

        return $this->accountingPeriodRepository->all($businessId);
    }

    /**
     * Ensure monthly periods exist from business registration year through next calendar year.
     */
    public function ensurePeriodsForBusiness(int $businessId): void
    {
        $business = Business::query()->find($businessId);
        if (!$business) {
            return;
        }

        $startYear = (int) $business->created_at->format('Y');
        $endYear = (int) now()->addYear()->format('Y');

        for ($year = $startYear; $year <= $endYear; $year++) {
            for ($month = 1; $month <= 12; $month++) {
                $date = sprintf('%d-%02d-15', $year, $month);
                $this->accountingPeriodRepository->findOrCreatePeriod($businessId, $date);
            }
        }
    }

    public function getById(int $id)
    {
        $period = $this->accountingPeriodRepository->find($id);
        if (!$period) {
            throw new \RuntimeException('Accounting period not found');
        }
        return $period;
    }

    public function getCurrentPeriod(int $businessId)
    {
        $period = $this->accountingPeriodRepository->getCurrentPeriod($businessId);
        if (!$period) {
            return $this->accountingPeriodRepository->findOrCreatePeriod($businessId, now()->toDateString());
        }
        return $period;
    }

    public function create(array $data)
    {
        $overlapping = $this->accountingPeriodRepository->getPeriodByDate($data['business_id'], $data['start_date']);
        if ($overlapping) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'start_date' => 'An accounting period already exists for this date range.',
            ]);
        }

        return $this->accountingPeriodRepository->create($data);
    }

    public function close(int $id, int $userId)
    {
        $period = $this->getById($id);

        if ($period->is_closed) {
            throw new \RuntimeException('Period is already closed.');
        }

        $trialBalance = $this->generalLedgerRepository->getTrialBalance($period->business_id, $id);
        $totalDebits = $trialBalance->sum('total_debits') + $trialBalance->sum('opening_balance');
        $totalCredits = $trialBalance->sum('total_credits') + $trialBalance->sum('opening_balance');

        if (abs($totalDebits - $totalCredits) > 0.01) {
            throw new \RuntimeException('Cannot close period: trial balance is not balanced.');
        }

        $unpostedCount = $this->journalEntryRepository->all($period->business_id, [
            'period_id' => $id,
            'locked' => false,
        ])->total();

        if ($unpostedCount > 0) {
            throw new \RuntimeException("Cannot close period: {$unpostedCount} unposted journal entries exist.");
        }

        return $this->accountingPeriodRepository->close($id, $userId);
    }

    public function reopen(int $id, int $userId)
    {
        $period = $this->getById($id);

        if (!$period->is_closed) {
            throw new \RuntimeException('Period is already open.');
        }

        return $this->accountingPeriodRepository->reopen($id, $userId);
    }
}
