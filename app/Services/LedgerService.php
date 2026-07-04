<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\GeneralLedger;
use App\Models\JournalEntry;
use App\Repositories\Contracts\GeneralLedgerRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LedgerService
{
    public function __construct(
        protected GeneralLedgerRepositoryInterface $generalLedgerRepository,
    ) {}

    public function postEntryToLedger(int $entryId): void
    {
        $entry = JournalEntry::with('lines.chartOfAccount')->findOrFail($entryId);

        if (!$entry->posted_at) {
            throw new \RuntimeException("Journal entry {$entry->entry_number} must be posted before posting to ledger.");
        }

        DB::transaction(function () use ($entry) {
            foreach ($entry->lines as $line) {
                $account = $line->chartOfAccount;
                $periodId = $entry->period_id;
                $businessId = $entry->business_id;

                $existingLedger = $this->generalLedgerRepository->getBalance($businessId, $account->id, $periodId);

                $openingBalance = $existingLedger ? (float) $existingLedger->opening_balance : $this->calculateOpeningBalance($businessId, $account->id, $periodId);
                $totalDebits = $existingLedger ? (float) $existingLedger->total_debits : 0;
                $totalCredits = $existingLedger ? (float) $existingLedger->total_credits : 0;

                $debit = (float) $line->debit_amount;
                $credit = (float) $line->credit_amount;

                $totalDebits += $debit;
                $totalCredits += $credit;

                if ($account->normal_balance === 'debit') {
                    $closingBalance = $openingBalance + $totalDebits - $totalCredits;
                } else {
                    $closingBalance = $openingBalance + $totalCredits - $totalDebits;
                }

                $this->generalLedgerRepository->updateOrCreate([
                    'business_id' => $businessId,
                    'account_id' => $account->id,
                    'period_id' => $periodId,
                    'opening_balance' => $openingBalance,
                    'total_debits' => $totalDebits,
                    'total_credits' => $totalCredits,
                    'closing_balance' => $closingBalance,
                ]);
            }

            Log::info("Ledger updated for entry {$entry->entry_number}", [
                'entry_id' => $entry->id,
                'business_id' => $entry->business_id,
            ]);
        });
    }

    public function calculateAccountBalance(int $accountId, int $businessId, ?int $periodId = null): float
    {
        $query = GeneralLedger::where('business_id', $businessId)
            ->where('account_id', $accountId);

        if ($periodId) {
            $query->where('period_id', $periodId);
        }

        return (float) $query->sum('closing_balance');
    }

    public function generateTrialBalance(int $businessId, int $periodId): array
    {
        $trialBalance = $this->generalLedgerRepository->getTrialBalance($businessId, $periodId);

        $rows = [];
        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($trialBalance as $row) {
            $balance = (float) $row->closing_balance;

            if ($row->normal_balance === 'debit') {
                $debitBalance = $balance;
                $creditBalance = 0;
            } else {
                $debitBalance = 0;
                $creditBalance = $balance;
            }

            $rows[] = [
                'account_code' => $row->account_code,
                'account_name' => $row->account_name,
                'debit' => $debitBalance,
                'credit' => $creditBalance,
            ];

            $totalDebits += $debitBalance;
            $totalCredits += $creditBalance;
        }

        return [
            'rows' => $rows,
            'total_debits' => round($totalDebits, 2),
            'total_credits' => round($totalCredits, 2),
            'period_id' => $periodId,
        ];
    }

    public function getGeneralLedger(int $businessId, int $periodId, ?int $accountId = null): array
    {
        $query = JournalEntry::where('business_id', $businessId)
            ->where('period_id', $periodId)
            ->whereNotNull('posted_at')
            ->with(['lines' => function ($q) use ($accountId) {
                if ($accountId) {
                    $q->where('account_id', $accountId);
                }
            }, 'lines.chartOfAccount']);

        $entries = $query->orderBy('date')->orderBy('id')->get();

        $rows = [];
        foreach ($entries as $entry) {
            foreach ($entry->lines as $line) {
                if ($accountId && $line->account_id !== $accountId) {
                    continue;
                }
                $rows[] = [
                    'date' => $entry->date->toDateString(),
                    'entry_number' => $entry->entry_number,
                    'description' => $line->description ?? $entry->description,
                    'account_code' => $line->chartOfAccount->code,
                    'account_name' => $line->chartOfAccount->name,
                    'debit' => (float) $line->debit_amount,
                    'credit' => (float) $line->credit_amount,
                ];
            }
        }

        return $rows;
    }

    public function getTrialBalance(int $businessId, int $periodId): \Illuminate\Support\Collection
    {
        return $this->generalLedgerRepository->getTrialBalance($businessId, $periodId);
    }

    protected function calculateOpeningBalance(int $businessId, int $accountId, int $periodId): float
    {
        $period = AccountingPeriod::find($periodId);
        if (!$period) {
            return 0;
        }

        $previousPeriods = AccountingPeriod::where('business_id', $businessId)
            ->where('end_date', '<', $period->start_date)
            ->pluck('id');

        if ($previousPeriods->isEmpty()) {
            return 0;
        }

        return (float) GeneralLedger::where('business_id', $businessId)
            ->where('account_id', $accountId)
            ->whereIn('period_id', $previousPeriods)
            ->sum('closing_balance');
    }
}
