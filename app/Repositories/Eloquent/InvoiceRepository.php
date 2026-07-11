<?php

namespace App\Repositories\Eloquent;

use App\Models\Invoice;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class InvoiceRepository implements InvoiceRepositoryInterface
{
    public function all(int $businessId, array $filters = []): Collection
    {
        $query = Invoice::query()
            ->where(function ($builder) use ($businessId) {
                $builder->where('business_id', $businessId)
                    ->orWhere(function ($received) use ($businessId) {
                        // Buyers only see non-draft invoices linked to their business.
                        $received->where('buyer_business_id', $businessId)
                            ->where('status', '!=', 'draft');
                    });
            })
            ->with([
                'customer',
                'createdBy',
                'purchaseOrder',
                'business:id,name',
                'payments' => fn ($q) => $q->orderBy('paid_at'),
            ]);

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
        if (!empty($filters['direction'])) {
            if ($filters['direction'] === 'issued') {
                $query->where('business_id', $businessId);
            } elseif ($filters['direction'] === 'received') {
                $query->where('buyer_business_id', $businessId)->where('status', '!=', 'draft');
            }
        }
        if (!empty($filters['purchase_order_id'])) {
            $query->where('purchase_order_id', (int) $filters['purchase_order_id']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function find(int $id): ?Invoice
    {
        return Invoice::with([
            'customer',
            'createdBy',
            'items.product',
            'purchaseOrder',
            'business:id,name',
            'payments' => fn ($q) => $q->orderBy('paid_at'),
        ])->find($id);
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
