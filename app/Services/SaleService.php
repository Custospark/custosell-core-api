<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Shift;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Repositories\Contracts\SaleRepositoryInterface;
use App\Services\Contracts\SaleServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use App\Support\TaxEngine;
use Illuminate\Support\Facades\DB;

use App\Events\SaleCreatedForAccounting;
use App\Events\SaleRefundedForAccounting;
use App\Services\PaymentService;

class SaleService implements SaleServiceInterface
{
    public function __construct(
        protected SaleRepositoryInterface $saleRepository,
        protected TaxEngine $taxEngine,
        protected PaymentService $paymentService,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->saleRepository->all($businessId);
    }

    public function getById(int $id): ?Sale
    {
        return $this->saleRepository->find($id);
    }

    public function create(int $businessId, int $userId, array $data): Sale
    {
        return DB::transaction(function () use ($businessId, $userId, $data) {
            $business = \App\Models\Business::findOrFail($businessId);
            $receiptNumber = $this->generateReceiptNumber($business);

            $shiftId = $this->resolveShiftId($businessId, $userId, $data['shift_id'] ?? null);

            $saleItemsInput = [];
            foreach ($data['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);
                $saleItemsInput[] = [
                    'product' => $product,
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'unit_price' => (float) ($item['unit_price'] ?? $product->unit_price),
                    'discount_amount' => (float) ($item['discount_amount'] ?? 0),
                ];
            }

            $computed = $this->taxEngine->computeSale(
                $business,
                $saleItemsInput,
                (float) ($data['discount_amount'] ?? 0),
            );

            $totalAmount = $computed['total_amount'];
            $amountPaid = $this->resolveInitialAmountPaid($data, $totalAmount);
            $isFullyPaid = abs($amountPaid - $totalAmount) < 0.01;
            $paymentStatus = $isFullyPaid ? 'paid' : 'partially_paid';

            $storedTendered = $this->resolveStoredAmountTendered($data, $amountPaid, $isFullyPaid);
            $storedChange = $this->resolveStoredChangeGiven($data, $amountPaid, $isFullyPaid);

            $sale = Sale::create([
                'business_id' => $businessId,
                'user_id' => $userId,
                'customer_id' => $data['customer_id'] ?? null,
                'shift_id' => $shiftId,
                'receipt_number' => $receiptNumber,
                'subtotal' => $computed['subtotal'],
                'tax_total' => $computed['tax_total'],
                'discount_amount' => $computed['discount_amount'],
                'total_amount' => $totalAmount,
                'amount_paid' => $amountPaid,
                'amount_tendered' => $storedTendered,
                'change_given' => $storedChange,
                'payment_method' => $data['payment_method'],
                'payment_status' => $paymentStatus,
                'notes' => $data['notes'] ?? null,
                'sale_date' => $data['sale_date'] ?? now(),
            ]);

            foreach ($computed['lines'] as $line) {
                $product = $line['product'];
                $qty = $line['quantity'];

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_price' => $product->unit_price,
                    'quantity' => $qty,
                    'unit_price' => $line['unit_price'],
                    'unit_cost' => (float) $product->cost_price,
                    'subtotal' => $line['subtotal'],
                    'tax_amount' => $line['tax_amount'],
                    'discount_amount' => (float) ($line['discount_amount'] ?? 0),
                ]);

                $stockBefore = $product->stock_quantity;

                if ($qty > $stockBefore) {
                    throw new \Illuminate\Validation\ValidationException(
                        validator([], []),
                        response()->json([
                            'message' => "Insufficient stock for {$product->name}. Only {$stockBefore} available, requested {$qty}.",
                            'errors' => ['items.*.quantity' => ["Only {$stockBefore} in stock for {$product->name}."]],
                        ], 422),
                    );
                }

                $stockAfter = $stockBefore - $qty;

                StockMovement::create([
                    'business_id' => $businessId,
                    'product_id' => $product->id,
                    'type' => 'sale',
                    'quantity_change' => -$qty,
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockAfter,
                    'notes' => "Sale {$receiptNumber}",
                ]);

                $product->stock_quantity = $stockAfter;
                $product->save();
            }

            if ($sale->customer_id) {
                $customer = \App\Models\Customer::find($sale->customer_id);
                if ($customer) {
                    $customer->total_purchases = ($customer->total_purchases ?? 0) + $computed['total_amount'];
                    $customer->last_purchase_at = now();
                    $customer->save();
                }
            }

            event(new SaleCreatedForAccounting($sale));

            if (!$isFullyPaid && $amountPaid > 0) {
                $this->paymentService->createInitialSalePayment(
                    $sale,
                    $amountPaid,
                    $data['payment_method'],
                    $userId,
                    $storedTendered !== null ? (float) $storedTendered : $amountPaid,
                    $storedChange !== null ? (float) $storedChange : null,
                );
            }

            return $sale->load(['saleItems', 'business', 'user', 'payments']);
        });
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
    ): \App\Models\Payment {
        $sale = $this->saleRepository->find($id);
        if (!$sale) {
            throw new \RuntimeException('Sale not found');
        }

        if ($sale->payment_status === 'paid') {
            throw new \RuntimeException('This sale is already fully paid');
        }

        return $this->paymentService->recordForSale(
            $sale,
            $amount,
            $paymentMethod,
            $userId,
            $notes,
            $amountTendered,
            $changeGiven,
            $attachmentPath,
        );
    }

    protected function resolveStoredAmountTendered(array $data, float $amountPaid, bool $isFullyPaid): ?float
    {
        if (!isset($data['amount_tendered']) && !isset($data['amount_paid'])) {
            return null;
        }

        if (!$isFullyPaid) {
            return $amountPaid;
        }

        return isset($data['amount_tendered']) ? (float) $data['amount_tendered'] : null;
    }

    protected function resolveStoredChangeGiven(array $data, float $amountPaid, bool $isFullyPaid): ?float
    {
        if (isset($data['change_given'])) {
            return (float) $data['change_given'];
        }

        if (!$isFullyPaid && isset($data['amount_tendered'])) {
            $tendered = (float) $data['amount_tendered'];
            $change = $tendered - $amountPaid;

            return $change > 0.009 ? round($change, 2) : null;
        }

        return null;
    }

    protected function resolveInitialAmountPaid(array $data, float $totalAmount): float
    {
        if (isset($data['amount_paid'])) {
            return min((float) $data['amount_paid'], $totalAmount);
        }

        $tendered = isset($data['amount_tendered']) ? (float) $data['amount_tendered'] : $totalAmount;

        return min(max(0, $tendered), $totalAmount);
    }

    public function update(int $id, array $data): Sale
    {
        $sale = $this->saleRepository->find($id);
        if (!$sale) throw new \RuntimeException('Sale not found');
        return $this->saleRepository->update($sale, $data);
    }

    public function delete(int $id): bool
    {
        $sale = $this->saleRepository->find($id);
        if (!$sale) throw new \RuntimeException('Sale not found');
        return $this->saleRepository->delete($sale);
    }

    public function bulkDelete(array $ids, int $businessId): int
    {
        $deleted = Sale::whereIn('id', $ids)
            ->where('business_id', $businessId)
            ->get();

        $count = 0;
        foreach ($deleted as $sale) {
            $sale->delete();
            $count++;
        }

        return $count;
    }

    public function getByDateRange(int $businessId, string $start, string $end): Collection
    {
        return $this->saleRepository->getByDateRange($businessId, $start, $end);
    }

    public function getByShift(int $businessId, int $shiftId): Collection
    {
        return $this->saleRepository->getByShift($businessId, $shiftId);
    }

    public function refund(int $id, array $data): Sale
    {
        return DB::transaction(function () use ($id, $data) {
            $sale = Sale::with('saleItems')->findOrFail($id);
            $saleSubtotal = (float) $sale->subtotal;
            $saleDiscount = (float) $sale->discount_amount;
            $discountRatio = $saleSubtotal > 0 ? $saleDiscount / $saleSubtotal : 0;

            $processedItems = [];
            $rawTotal = 0;

            foreach ($data['items'] as $refundItem) {
                $saleItem = SaleItem::findOrFail($refundItem['id']);
                $refundQty = (int) ($refundItem['quantity'] ?? $saleItem->quantity);

                if ($saleItem->refunded_quantity + $refundQty > $saleItem->quantity) {
                    abort(422, "Cannot refund {$refundQty} of '{$saleItem->product_name}'. Only " . ($saleItem->quantity - $saleItem->refunded_quantity) . " remaining.");
                }

                $rawAmount = $saleItem->unit_price * $refundQty;
                $rawTotal += $rawAmount;
                $proportionalAmount = $rawAmount * (1 - $discountRatio);

                $processedItems[] = [
                    'saleItem' => $saleItem,
                    'refundQty' => $refundQty,
                    'proportionalAmount' => $proportionalAmount,
                    'rawAmount' => $rawAmount,
                ];
            }

            // Expected total refund for this batch = raw total minus proportional share of discount
            $expectedTotal = $rawTotal * (1 - $discountRatio);

            foreach ($processedItems as $i => $pi) {
                $isLast = $i === count($processedItems) - 1;
                $refundAmount = $pi['proportionalAmount'];

                // Absorb any rounding difference into the last item
                if ($isLast && count($processedItems) > 1) {
                    $sumOthers = collect($processedItems)->take(count($processedItems) - 1)->sum('proportionalAmount');
                    $refundAmount = $expectedTotal - $sumOthers;
                }

                $refundAmount = round($refundAmount, 2);

                $taxRefund = $this->taxEngine->computeLineTaxRefund(
                    (float) $pi['saleItem']->tax_amount,
                    (int) $pi['saleItem']->quantity,
                    $pi['refundQty'],
                );

                $pi['saleItem']->refunded_quantity += $pi['refundQty'];
                $pi['saleItem']->refunded_amount += $refundAmount;
                $pi['saleItem']->tax_refunded_amount = round((float) $pi['saleItem']->tax_refunded_amount + $taxRefund, 2);
                $pi['saleItem']->save();
                $pi['saleItem']->refresh();

                // Restore stock
                $product = Product::find($pi['saleItem']->product_id);
                if ($product) {
                    $stockBefore = $product->stock_quantity;
                    $product->stock_quantity += $pi['refundQty'];
                    $product->save();

                    StockMovement::create([
                        'business_id' => $sale->business_id,
                        'product_id' => $product->id,
                        'sale_item_id' => $pi['saleItem']->id,
                        'type' => 'return',
                        'quantity_change' => $pi['refundQty'],
                        'stock_before' => $stockBefore,
                        'stock_after' => $product->stock_quantity,
                        'notes' => "Refund from sale {$sale->receipt_number}",
                    ]);
                }
            }

            $totalRefunded = (float) SaleItem::where('sale_id', $sale->id)->sum('refunded_amount');
            $saleTotal = (float) $sale->total_amount;
            $sale->payment_status = abs($totalRefunded - $saleTotal) < 0.01 ? 'refunded' : ($totalRefunded > 0 ? 'partially_refunded' : 'paid');
            $sale->save();

            event(new SaleRefundedForAccounting($sale, $processedItems));

            return $sale->load(['saleItems', 'business', 'user']);
        });
    }

    public function getDaily(int $businessId, ?string $date = null): Collection
    {
        $date = $date ?? now()->toDateString();
        return $this->saleRepository->getByDateRange($businessId, $date . ' 00:00:00', $date . ' 23:59:59');
    }

    public function getByCustomer(int $businessId, int $customerId): Collection
    {
        return $this->saleRepository->getByCustomer($businessId, $customerId);
    }

    protected function resolveShiftId(int $businessId, int $userId, ?int $shiftId): ?int
    {
        if ($shiftId) {
            return $shiftId;
        }

        return Shift::query()
            ->where('business_id', $businessId)
            ->where('user_id', $userId)
            ->whereNull('clock_out')
            ->where('status', 'active')
            ->value('id');
    }

    protected function generateReceiptNumber(\App\Models\Business $business): string
    {
        return DocumentNumberGenerator::saleReceiptNumber($business);
    }
}
