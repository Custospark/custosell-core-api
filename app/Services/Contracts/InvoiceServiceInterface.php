<?php

namespace App\Services\Contracts;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;

interface InvoiceServiceInterface
{
    public function getAll(int $businessId, array $filters = []): Collection;

    public function getById(int $id): ?Invoice;

    public function create(int $businessId, int $userId, array $data): Invoice;

    public function update(int $id, array $data): Invoice;

    public function delete(int $id): bool;

    public function send(int $id): Invoice;

    public function recordPayment(int $id, float $amount, string $paymentMethod): Invoice;
}
