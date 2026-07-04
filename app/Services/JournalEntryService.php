<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Repositories\Contracts\AccountingPeriodRepositoryInterface;
use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use App\Repositories\Contracts\GeneralLedgerRepositoryInterface;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JournalEntryService
{
    public function __construct(
        protected JournalEntryRepositoryInterface $journalEntryRepository,
        protected ChartOfAccountRepositoryInterface $chartOfAccountRepository,
        protected AccountingPeriodRepositoryInterface $accountingPeriodRepository,
        protected GeneralLedgerRepositoryInterface $generalLedgerRepository,
        protected \App\Services\LedgerService $ledgerService,
    ) {}

    public function createEntry(
        int $businessId,
        string $date,
        string $description,
        array $lines,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $createdBy = null,
    ): JournalEntry {
        return DB::transaction(function () use ($businessId, $date, $description, $lines, $referenceType, $referenceId, $createdBy) {
            $period = $this->accountingPeriodRepository->getPeriodByDate($businessId, $date);

            if (!$period) {
                throw new \RuntimeException("No open accounting period found for date {$date}");
            }

            if ($period->is_closed) {
                throw new \RuntimeException("The accounting period '{$period->name}' is closed. Cannot create entries.");
            }

            $totalDebits = 0;
            $totalCredits = 0;
            $entryLines = [];

            foreach ($lines as $line) {
                $account = null;

                if (!empty($line['account_code'])) {
                    $account = $this->chartOfAccountRepository->findByCode($businessId, $line['account_code']);
                } elseif (!empty($line['account_id'])) {
                    $account = $this->chartOfAccountRepository->find($line['account_id']);
                }

                if (!$account) {
                    $identifier = $line['account_code'] ?? $line['account_id'] ?? 'unknown';
                    throw new \RuntimeException("Account '{$identifier}' not found for this business.");
                }

                $debit = (float) ($line['debit'] ?? 0);
                $credit = (float) ($line['credit'] ?? 0);

                $totalDebits += $debit;
                $totalCredits += $credit;

                $entryLines[] = [
                    'account_id' => $account->id,
                    'debit_amount' => $debit,
                    'credit_amount' => $credit,
                    'description' => $line['description'] ?? null,
                ];
            }

            if (abs($totalDebits - $totalCredits) > 0.01) {
                throw new \RuntimeException("Journal entry is not balanced. Total debits: {$totalDebits}, Total credits: {$totalCredits}");
            }

            $entryNumber = $this->journalEntryRepository->generateEntryNumber($businessId, $date);

            $entry = $this->journalEntryRepository->create([
                'business_id' => $businessId,
                'entry_number' => $entryNumber,
                'date' => $date,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'period_id' => $period->id,
                'created_by' => $createdBy ?? auth()->id() ?? 1,
                'locked' => false,
                'posted_at' => null,
            ]);

            $this->journalEntryRepository->createLines($entry->id, $entryLines);

            Log::info("Journal entry {$entryNumber} created", [
                'business_id' => $businessId,
                'entry_id' => $entry->id,
                'total_debits' => $totalDebits,
                'total_credits' => $totalCredits,
                'reference' => $referenceType ? "{$referenceType}:{$referenceId}" : null,
            ]);

            return $this->journalEntryRepository->find($entry->id);
        });
    }

    public function postEntry(int $entryId): JournalEntry
    {
        $entry = $this->journalEntryRepository->find($entryId);
        if (!$entry) {
            throw new \RuntimeException("Journal entry not found.");
        }

        if ($entry->posted_at) {
            throw new \RuntimeException("Journal entry {$entry->entry_number} is already posted.");
        }

        $entry = $this->journalEntryRepository->lock($entryId);
        $entry->update(['posted_at' => now()]);

        $this->ledgerService->postEntryToLedger($entryId);

        Log::info("Journal entry {$entry->entry_number} posted", [
            'entry_id' => $entry->id,
            'business_id' => $entry->business_id,
        ]);

        return $entry->fresh('lines');
    }

    public function createAndPostEntry(
        int $businessId,
        string $date,
        string $description,
        array $lines,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $createdBy = null,
    ): JournalEntry {
        $entry = $this->createEntry($businessId, $date, $description, $lines, $referenceType, $referenceId, $createdBy);
        return $this->postEntry($entry->id);
    }

    public function createReversingEntry(int $originalEntryId, ?int $createdBy = null): JournalEntry
    {
        return DB::transaction(function () use ($originalEntryId, $createdBy) {
            $original = $this->journalEntryRepository->find($originalEntryId);
            if (!$original) {
                throw new \RuntimeException("Original journal entry not found.");
            }

            if (!$original->posted_at) {
                throw new \RuntimeException("Cannot reverse an unposted entry.");
            }

            $originalLines = $this->journalEntryRepository->getLines($originalEntryId);

            $reversingLines = [];
            foreach ($originalLines as $line) {
                $reversingLines[] = [
                    'account_code' => $line->chartOfAccount->code,
                    'debit' => (float) $line->credit_amount,
                    'credit' => (float) $line->debit_amount,
                    'description' => "Reversing: {$line->description}",
                ];
            }

            $userId = $createdBy ?? auth()->id() ?? 1;

            $reversing = $this->createEntry(
                $original->business_id,
                now()->toDateString(),
                "Reversing entry for {$original->entry_number}: {$original->description}",
                $reversingLines,
                $original->reference_type,
                $original->reference_id,
                $userId,
            );

            $this->postEntry($reversing->id);

            Log::info("Reversing entry {$reversing->entry_number} created for {$original->entry_number}", [
                'original_entry_id' => $original->id,
                'reversing_entry_id' => $reversing->id,
            ]);

            return $reversing->fresh('lines');
        });
    }

    public function getEntryByReference(string $referenceType, int $referenceId, int $businessId): ?JournalEntry
    {
        $entries = $this->journalEntryRepository->getByReference($referenceType, $referenceId);

        return $entries
            ->where('business_id', $businessId)
            ->sortByDesc('id')
            ->first();
    }

    // ── Adapter methods for controller compatibility ──

    public function getAll(int $businessId, array $filters = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        return $this->journalEntryRepository->all($businessId, $filters);
    }

    public function getById(int $id): ?JournalEntry
    {
        return $this->journalEntryRepository->find($id);
    }

    public function createDraft(int $businessId, int $userId, array $data): JournalEntry
    {
        $lines = [];
        foreach ($data['lines'] ?? [] as $line) {
            $lines[] = [
                'account_code' => $line['account_code'] ?? null,
                'account_id' => $line['account_id'] ?? null,
                'debit' => $line['debit_amount'] ?? $line['debit'] ?? 0,
                'credit' => $line['credit_amount'] ?? $line['credit'] ?? 0,
                'description' => $line['description'] ?? null,
            ];
        }

        return $this->createEntry(
            $businessId,
            $data['date'],
            $data['description'],
            $lines,
            $data['reference_type'] ?? null,
            $data['reference_id'] ?? null,
            $userId,
        );
    }

    public function post(int $id, int $userId): JournalEntry
    {
        $entry = $this->postEntry($id);
        Log::info("Journal entry {$entry->entry_number} posted by user {$userId}");
        return $entry;
    }

    public function getLines(int $entryId): \Illuminate\Database\Eloquent\Collection
    {
        return $this->journalEntryRepository->getLines($entryId);
    }
}
