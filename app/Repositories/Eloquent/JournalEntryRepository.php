<?php

namespace App\Repositories\Eloquent;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class JournalEntryRepository implements JournalEntryRepositoryInterface
{
    public function all(int $businessId, array $filters = []): LengthAwarePaginator
    {
        $query = JournalEntry::where('business_id', $businessId)
            ->with(['createdBy', 'accountingPeriod']);

        if (!empty($filters['period_id'])) {
            if (str_contains($filters['period_id'], ',')) {
                $ids = array_map('intval', explode(',', $filters['period_id']));
                $query->whereIn('period_id', $ids);
            } else {
                $query->where('period_id', $filters['period_id']);
            }
        }
        if (!empty($filters['date_from'])) {
            $query->where('date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('date', '<=', $filters['date_to']);
        }
        if (!empty($filters['reference_type'])) {
            $query->where('reference_type', $filters['reference_type']);
        }
        if (isset($filters['locked'])) {
            $query->where('locked', $filters['locked']);
        }

        return $query->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function find(int $id): ?JournalEntry
    {
        return JournalEntry::with(['createdBy', 'accountingPeriod', 'lines.chartOfAccount'])->find($id);
    }

    public function findByNumber(int $businessId, string $entryNumber): ?JournalEntry
    {
        return JournalEntry::where('business_id', $businessId)
            ->where('entry_number', $entryNumber)
            ->first();
    }

    public function create(array $data): JournalEntry
    {
        return JournalEntry::create($data);
    }

    public function createLines(int $entryId, array $lines): void
    {
        $data = array_map(fn ($line) => array_merge($line, ['entry_id' => $entryId]), $lines);
        JournalEntryLine::insert($data);
    }

    public function getLines(int $entryId): Collection
    {
        return JournalEntryLine::where('entry_id', $entryId)
            ->with(['chartOfAccount'])
            ->get();
    }

    public function getByReference(string $type, int $refId): Collection
    {
        return JournalEntry::where('reference_type', $type)
            ->where('reference_id', $refId)
            ->with(['lines.chartOfAccount'])
            ->get();
    }

    public function generateEntryNumber(int $businessId, string $date): string
    {
        $prefix = 'JE-' . \Carbon\Carbon::parse($date)->format('Ym') . '-';

        $last = JournalEntry::where('business_id', $businessId)
            ->where('entry_number', 'like', $prefix . '%')
            ->orderBy('entry_number', 'desc')
            ->lockForUpdate()
            ->withTrashed()
            ->first();

        if ($last) {
            $lastSeq = (int) substr($last->entry_number, -5);
            $seq = str_pad($lastSeq + 1, 5, '0', STR_PAD_LEFT);
        } else {
            $seq = '00001';
        }

        return $prefix . $seq;
    }

    public function lock(int $id): JournalEntry
    {
        $entry = $this->find($id);
        $entry->update(['locked' => true]);
        return $entry->fresh();
    }
}
