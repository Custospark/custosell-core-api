<?php

namespace App\Services;

use App\Events\InvoicePaidForAccounting;
use App\Events\InvoiceSentForAccounting;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Services\Contracts\InvoiceServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InvoiceService implements InvoiceServiceInterface
{
    public function __construct(
        protected InvoiceRepositoryInterface $invoiceRepository,
    ) {}

    public function getAll(int $businessId, array $filters = []): LengthAwarePaginator
    {
        return $this->invoiceRepository->all($businessId, $filters);
    }

    public function getById(int $id): ?Invoice
    {
        return $this->invoiceRepository->find($id);
    }

    public function create(int $businessId, int $userId, array $data): Invoice
    {
        return DB::transaction(function () use ($businessId, $userId, $data) {
            $business = Business::findOrFail($businessId);
            $invoiceNumber = $this->generateInvoiceNumber($business);

            $subtotal = 0;
            foreach ($data['items'] as $item) {
                $lineSubtotal = (float) ($item['quantity'] ?? 1) * (float) ($item['unit_price'] ?? 0);
                $subtotal += $lineSubtotal;
            }

            $taxTotal = (float) ($data['tax_total'] ?? 0);
            $totalAmount = $subtotal + $taxTotal;

            $invoice = $this->invoiceRepository->create([
                'business_id' => $businessId,
                'invoice_number' => $invoiceNumber,
                'customer_id' => $data['customer_id'] ?? null,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'status' => 'draft',
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'total_amount' => $totalAmount,
                'amount_paid' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($data['items'] as $item) {
                $lineQty = (float) ($item['quantity'] ?? 1);
                $linePrice = (float) ($item['unit_price'] ?? 0);
                $lineSubtotal = $lineQty * $linePrice;

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $item['product_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $lineQty,
                    'unit_price' => $linePrice,
                    'subtotal' => $lineSubtotal,
                ]);
            }

            return $invoice->load(['customer', 'createdBy', 'items.product']);
        });
    }

    public function update(int $id, array $data): Invoice
    {
        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice) {
            throw new \RuntimeException('Invoice not found');
        }

        return DB::transaction(function () use ($invoice, $data) {
            if (isset($data['items'])) {
                $invoice->items()->delete();

                $subtotal = 0;
                foreach ($data['items'] as $item) {
                    $lineQty = (float) ($item['quantity'] ?? 1);
                    $linePrice = (float) ($item['unit_price'] ?? 0);
                    $lineSubtotal = $lineQty * $linePrice;
                    $subtotal += $lineSubtotal;

                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'product_id' => $item['product_id'] ?? null,
                        'description' => $item['description'],
                        'quantity' => $lineQty,
                        'unit_price' => $linePrice,
                        'subtotal' => $lineSubtotal,
                    ]);
                }

                $data['subtotal'] = $subtotal;
                $taxTotal = (float) ($data['tax_total'] ?? $invoice->tax_total);
                $data['tax_total'] = $taxTotal;
                $data['total_amount'] = $subtotal + $taxTotal;
            }

            return $this->invoiceRepository->update($invoice, $data);
        });
    }

    public function delete(int $id): bool
    {
        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice) {
            throw new \RuntimeException('Invoice not found');
        }

        if ($invoice->status !== 'draft') {
            throw new \RuntimeException('Only draft invoices can be deleted');
        }

        return $this->invoiceRepository->delete($invoice);
    }

    public function send(int $id): Invoice
    {
        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice) {
            throw new \RuntimeException('Invoice not found');
        }

        if (!in_array($invoice->status, ['draft', 'cancelled'])) {
            throw new \RuntimeException('Only draft or cancelled invoices can be sent');
        }

        $invoice = $this->invoiceRepository->update($invoice, [
            'status' => 'sent',
        ]);

        event(new InvoiceSentForAccounting($invoice));

        return $invoice;
    }

    public function recordPayment(int $id, float $amount, string $paymentMethod = 'cash'): Invoice
    {
        return DB::transaction(function () use ($id, $amount, $paymentMethod) {
            $invoice = $this->invoiceRepository->find($id);
            if (!$invoice) {
                throw new \RuntimeException('Invoice not found');
            }

            $newAmountPaid = (float) $invoice->amount_paid + $amount;
            $total = (float) $invoice->total_amount;

            if ($newAmountPaid > $total) {
                throw new \RuntimeException('Payment amount exceeds invoice total');
            }

            $status = abs($newAmountPaid - $total) < 0.01 ? 'paid' : 'partially_paid';

            $invoice = $this->invoiceRepository->update($invoice, [
                'amount_paid' => $newAmountPaid,
                'status' => $status,
            ]);

            event(new InvoicePaidForAccounting($invoice, $amount, $paymentMethod));

            return $invoice;
        });
    }

    protected function generateInvoiceNumber(Business $business): string
    {
        $prefix = 'INV';
        $ym = now()->format('Ym');

        $lastInvoice = Invoice::where('business_id', $business->id)
            ->where('invoice_number', 'like', "{$prefix}-{$ym}-%")
            ->orderBy('id', 'desc')
            ->first();

        if ($lastInvoice) {
            $parts = explode('-', $lastInvoice->invoice_number);
            $seq = ((int) end($parts)) + 1;
        } else {
            $seq = 1;
        }

        return sprintf('%s-%s-%05d', $prefix, $ym, $seq);
    }
}
