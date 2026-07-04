<?php

namespace App\Repositories\Contracts;

use App\Models\JournalEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface JournalEntryRepositoryInterface
{
    public function all(int $businessId, array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?JournalEntry;

    public function findByNumber(int $businessId, string $entryNumber): ?JournalEntry;

    public function create(array $data): JournalEntry;

    public function createLines(int $entryId, array $lines): void;

    public function getLines(int $entryId): Collection;

    public function getByReference(string $type, int $refId): Collection;

    public function generateEntryNumber(int $businessId, string $date): string;

    public function lock(int $id): JournalEntry;
}
