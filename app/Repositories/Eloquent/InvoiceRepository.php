<?php

namespace App\Repositories\Eloquent;

use App\Models\Invoice;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class InvoiceRepository implements InvoiceRepositoryInterface
{
    public function all(int $businessId, array $filters = []): LengthAwarePaginator
    {
        $query = Invoice::where('business_id', $businessId)
            ->with(['customer', 'createdBy']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('issue_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('issue_date', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function find(int $id): ?Invoice
    {
        return Invoice::with(['customer', 'createdBy', 'items.product'])->find($id);
    }

    public function findByNumber(int $businessId, string $number): ?Invoice
    {
        return Invoice::where('business_id', $businessId)
            ->where('invoice_number', $number)
            ->with(['customer', 'createdBy', 'items.product'])
            ->first();
    }

    public function create(array $data): Invoice
    {
        return Invoice::create($data);
    }

    public function update(Invoice $invoice, array $data): Invoice
    {
        $invoice->update($data);
        return $invoice->fresh();
    }

    public function delete(Invoice $invoice): bool
    {
        return $invoice->delete();
    }
}
