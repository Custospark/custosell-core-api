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
            $lineItems = [];
            foreach ($data['items'] as $item) {
                $lineQty = (float) ($item['quantity'] ?? 1);
                $linePrice = (float) ($item['unit_price'] ?? 0);
                $lineDisc = max(0, (float) ($item['discount_amount'] ?? 0));
                $lineSubtotal = $lineQty * $linePrice - $lineDisc;
                $subtotal += $lineSubtotal;
                $tier = $item['price_tier'] ?? 'retail';
                $lineItems[] = [
                    'product_id' => $item['product_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $lineQty,
                    'unit_price' => $linePrice,
                    'price_tier' => in_array($tier, ['retail', 'wholesale'], true) ? $tier : 'retail',
                    'discount_amount' => $lineDisc,
                    'subtotal' => $lineSubtotal,
                ];
            }

            $taxTotal = (float) ($data['tax_total'] ?? 0);
            $subtotal = isset($data['subtotal']) ? (float) $data['subtotal'] : $subtotal;
            $discountAmount = max(0, (float) ($data['discount_amount'] ?? 0));
            $totalAmount = isset($data['total_amount'])
                ? (float) $data['total_amount']
                : max(0, $subtotal + $taxTotal);

            $locationId = $this->resolveLocationId($businessId, $userId, $data['location_id'] ?? null);
            $this->assertBranchStockAvailable($businessId, $locationId, $lineItems);

            $invoice = $this->invoiceRepository->create([
                'business_id' => $businessId,
                'invoice_number' => $invoiceNumber,
                'customer_id' => $data['customer_id'] ?? null,
                'sale_id' => $data['sale_id'] ?? null,
                'estimate_id' => $data['estimate_id'] ?? null,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'buyer_business_id' => $data['buyer_business_id'] ?? null,
                'location_id' => $locationId,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'status' => 'draft',
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'tax_total' => $taxTotal,
                'total_amount' => $totalAmount,
                'amount_paid' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($lineItems as $lineItem) {
                InvoiceItem::create(array_merge($lineItem, ['invoice_id' => $invoice->id]));
            }

            if (!empty($data['sale_id'])) {
                $invoice = $this->paymentService->syncInvoiceFromLinkedSale($invoice->fresh());
                $this->orderService->markInvoicedForSale((int) $data['sale_id']);
            }

            return $invoice->load(['customer', 'createdBy', 'items.product', 'payments', 'purchaseOrder']);
        });
    }

    protected function resolveLocationId(int $businessId, int $userId, ?int $locationId): ?int
    {
        if ($locationId) {
            $exists = \App\Models\Location::forBusiness($businessId)->where('id', $locationId)->exists();
            if ($exists) {
                return $locationId;
            }
        }

        $userLocation = \App\Models\User::find($userId)?->location_id;
        if ($userLocation && \App\Models\Location::forBusiness($businessId)->where('id', $userLocation)->exists()) {
            return $userLocation;
        }

        return \App\Services\LocationService::ensureDefault($businessId)?->id;
    }

    /**
     * Physical goods on an invoice may not exceed what the branch has on hand.
     * Services and custom (non-product) lines are exempt.
     */
    protected function assertBranchStockAvailable(int $businessId, ?int $locationId, array $items): void
    {
        foreach ($items as $item) {
            $productId = isset($item['product_id']) ? (int) $item['product_id'] : null;
            $qty = (float) ($item['quantity'] ?? 0);

            if (!$productId || $qty <= 0) {
                continue;
            }

            $product = \App\Models\Product::find($productId);
            if (!$product || !$product->tracksStock()) {
                continue;
            }

            $available = $locationId
                ? (float) (\App\Models\LocationProduct::where('business_id', $businessId)
                    ->where('location_id', $locationId)
                    ->where('product_id', $productId)
                    ->value('stock_quantity') ?? 0)
                : (float) $product->stock_quantity;

            if ($qty > $available) {
                throw new \Illuminate\Validation\ValidationException(
                    validator([], []),
                    response()->json([
                        'message' => "Insufficient stock for {$product->name}. Only {$available} available at this branch, requested {$qty}.",
                        'errors' => ['items.*.quantity' => ["Only {$available} in stock at this branch for {$product->name}."]],
                    ], 422),
                );
            }
        }
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
                return $existing->load(['customer', 'createdBy', 'items.product', 'payments', 'purchaseOrder']);
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
                    'quantity' => (float) $item->quantity,
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
        // Seller-only: only the issuing business records invoice payments.
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
                $newItems = [];
                foreach ($data['items'] as $item) {
                    $lineQty = (float) ($item['quantity'] ?? 1);
                    $linePrice = (float) ($item['unit_price'] ?? 0);
                    $lineDisc = max(0, (float) ($item['discount_amount'] ?? 0));
                    $lineSubtotal = $lineQty * $linePrice - $lineDisc;
                    $subtotal += $lineSubtotal;

                    $newItems[] = [
                        'product_id' => $item['product_id'] ?? null,
                        'description' => $item['description'],
                        'quantity' => $lineQty,
                        'unit_price' => $linePrice,
                        'price_tier' => in_array($item['price_tier'] ?? 'retail', ['retail', 'wholesale'], true)
                            ? $item['price_tier']
                            : 'retail',
                        'discount_amount' => $lineDisc,
                        'subtotal' => $lineSubtotal,
                    ];
                }

                $effectiveLocationId = isset($data['location_id'])
                    ? $this->resolveLocationId((int) $invoice->business_id, (int) $invoice->created_by, $data['location_id'])
                    : $invoice->location_id;

                $this->assertBranchStockAvailable(
                    (int) $invoice->business_id,
                    $effectiveLocationId,
                    $newItems,
                );

                foreach ($newItems as $newItem) {
                    InvoiceItem::create(array_merge($newItem, ['invoice_id' => $invoice->id]));
                }

                $subtotal = isset($data['subtotal']) ? (float) $data['subtotal'] : $subtotal;
                $data['subtotal'] = $subtotal;
                $data['discount_amount'] = max(0, (float) ($data['discount_amount'] ?? $invoice->discount_amount));
                $taxTotal = (float) ($data['tax_total'] ?? $invoice->tax_total);
                $data['tax_total'] = $taxTotal;
                $data['total_amount'] = isset($data['total_amount'])
                    ? (float) $data['total_amount']
                    : max(0, $subtotal + $taxTotal);
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
