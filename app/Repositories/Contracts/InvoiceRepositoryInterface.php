<?php

namespace App\Repositories\Contracts;

use App\Models\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface InvoiceRepositoryInterface
{
    public function all(int $businessId, array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Invoice;

    public function findByNumber(int $businessId, string $number): ?Invoice;

    public function create(array $data): Invoice;

    public function update(Invoice $invoice, array $data): Invoice;

    public function delete(Invoice $invoice): bool;
}
