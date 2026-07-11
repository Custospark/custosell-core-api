<?php

namespace App\Services;

use App\Events\InvoiceSentForAccounting;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Sale;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Services\Contracts\InvoiceServiceInterface;
use App\Services\Contracts\OrderServiceInterface;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class InvoiceService implements InvoiceServiceInterface
{
    public function __construct(
        protected InvoiceRepositoryInterface $invoiceRepository,
        protected PaymentService $paymentService,
        protected OrderServiceInterface $orderService,
    ) {}

    public function getAll(int $businessId, array $filters = []): Collection
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
                'sale_id' => $data['sale_id'] ?? null,
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

            if (!empty($data['sale_id'])) {
                $invoice = $this->paymentService->syncInvoiceFromLinkedSale($invoice->fresh());
                $this->orderService->markInvoicedForSale((int) $data['sale_id']);
            }

            return $invoice->load(['customer', 'createdBy', 'items.product', 'payments']);
        });
    }

    public function update(int $id, array $data): Invoice
    {
        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice) {
            throw new \RuntimeException('Invoice not found');
        }

        if ($invoice->status !== 'draft') {
            throw new \RuntimeException('Only draft invoices can be updated');
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

            return $this->invoiceRepository->update($invoice, $data)
                ->load(['customer', 'createdBy', 'items.product']);
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

        if ($invoice->sale_id) {
            $invoice = $this->paymentService->syncInvoiceFromLinkedSale($invoice->fresh());
        }

        $status = $this->resolveStatusAfterSend($invoice);

        $invoice = $this->invoiceRepository->update($invoice, [
            'status' => $status,
        ]);

        event(new InvoiceSentForAccounting($invoice));

        return $invoice->load(['customer', 'createdBy', 'items.product', 'payments']);
    }

    public function recordPayment(
        int $id,
        float $amount,
        string $paymentMethod,
        int $userId,
        ?string $notes = null,
        ?float $amountTendered = null,
        ?float $changeGiven = null,
        ?string $attachmentPath = null,
        ?int $shiftId = null,
    ): array {
        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice) {
            throw new \RuntimeException('Invoice not found');
        }

        $payment = $this->paymentService->recordForInvoice(
            $invoice,
            $amount,
            $paymentMethod,
            $userId,
            $notes,
            $amountTendered,
            $changeGiven,
            $attachmentPath,
            $shiftId,
        );

        return [
            'invoice' => $invoice->fresh(['customer', 'createdBy', 'items.product', 'payments']),
            'payment' => $payment,
        ];
    }

    protected function generateInvoiceNumber(Business $business): string
    {
        return DocumentNumberGenerator::invoiceNumber($business, Invoice::class, 'invoice_number');
    }

    protected function resolveStatusAfterSend(Invoice $invoice): string
    {
        $total = (float) $invoice->total_amount;
        $paid = (float) $invoice->amount_paid;
        $balance = max(0, $total - $paid);

        if ($balance < 0.01) {
            return 'paid';
        }

        if ($paid > 0) {
            return 'partially_paid';
        }

        return 'sent';
    }
}
