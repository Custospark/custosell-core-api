<?php

namespace App\Services;

use App\Events\InvoiceSentForAccounting;
use App\Models\Business;
use App\Models\Customer;
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
                'estimate_id' => $data['estimate_id'] ?? null,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'buyer_business_id' => $data['buyer_business_id'] ?? null,
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

            return $invoice->load(['customer', 'createdBy', 'items.product', 'payments', 'purchaseOrder', 'business']);
        });
    }

    /**
     * Create (and send) a seller invoice from an accepted purchase order.
     * Visible to the buyer via buyer_business_id.
     */
    public function createFromPurchaseOrder(\App\Models\PurchaseOrder $po, int $sellerUserId): Invoice
    {
        return DB::transaction(function () use ($po, $sellerUserId) {
            $existing = Invoice::query()
                ->where('purchase_order_id', $po->id)
                ->first();
            if ($existing) {
                return $existing->load(['customer', 'createdBy', 'items.product', 'payments', 'purchaseOrder', 'business']);
            }

            $po->loadMissing(['items', 'buyerBusiness']);
            $buyer = $po->buyerBusiness;
            $buyerName = $buyer?->name ?? ('Business #'.$po->buyer_business_id);

            $customer = Customer::query()->firstOrCreate(
                [
                    'business_id' => $po->seller_business_id,
                    'name' => $buyerName,
                ],
                [
                    'email' => $buyer?->business_email,
                    'phone' => $buyer?->business_phone,
                ],
            );

            $items = [];
            foreach ($po->items as $item) {
                $items[] = [
                    'product_id' => $item->product_id,
                    'description' => $item->product_name,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                ];
            }

            $discount = (float) $po->discount_amount;
            if ($discount > 0) {
                $items[] = [
                    'product_id' => null,
                    'description' => 'Purchase order discount',
                    'quantity' => 1,
                    'unit_price' => -1 * $discount,
                ];
            }

            $invoice = $this->create((int) $po->seller_business_id, $sellerUserId, [
                'customer_id' => $customer->id,
                'purchase_order_id' => $po->id,
                'buyer_business_id' => $po->buyer_business_id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'tax_total' => (float) $po->tax_total,
                'notes' => 'Invoice for purchase order '.$po->po_number,
                'items' => $items,
            ]);

            return $this->send($invoice->id);
        });
    }

    public function getVisibleForBusiness(int $id, int $businessId): ?Invoice
    {
        $invoice = $this->invoiceRepository->find($id);
        if (! $invoice) {
            return null;
        }

        if ((int) $invoice->business_id === $businessId) {
            return $invoice;
        }

        if (
            $invoice->buyer_business_id
            && (int) $invoice->buyer_business_id === $businessId
            && $invoice->status !== 'draft'
        ) {
            return $invoice;
        }

        return null;
    }

    public function isOwnedByBusiness(Invoice $invoice, int $businessId): bool
    {
        return (int) $invoice->business_id === $businessId;
    }

    public function canManagePayments(Invoice $invoice, int $businessId): bool
    {
        // Only the issuing business (seller) may record payments. Buyers can view
        // received invoices and payment history but cannot post payments.
        return $this->isOwnedByBusiness($invoice, $businessId);
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
