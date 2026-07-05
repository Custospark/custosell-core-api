<?php

namespace App\Repositories\Contracts;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;

interface InvoiceRepositoryInterface
{
    public function all(int $businessId, array $filters = []): Collection;

    public function find(int $id): ?Invoice;

    public function findByNumber(int $businessId, string $number): ?Invoice;

    public function create(array $data): Invoice;

    public function update(Invoice $invoice, array $data): Invoice;

    public function delete(Invoice $invoice): bool;
}
