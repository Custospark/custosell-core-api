<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Repositories\Contracts\SaleRepositoryInterface;
use App\Services\Contracts\SaleServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SaleService implements SaleServiceInterface
{
    public function __construct(
        protected SaleRepositoryInterface $saleRepository,
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
            $business = \App\Models\Business::find($businessId);
            $receiptNumber = $this->generateReceiptNumber($business);

            $sale = Sale::create([
                'business_id' => $businessId,
                'user_id' => $userId,
                'customer_id' => $data['customer_id'] ?? null,
                'shift_id' => $data['shift_id'] ?? null,
                'receipt_number' => $receiptNumber,
                'subtotal' => $data['subtotal'],
                'tax_total' => $data['tax_total'] ?? 0,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'total_amount' => $data['total_amount'],
                'payment_method' => $data['payment_method'],
                'payment_status' => 'paid',
                'notes' => $data['notes'] ?? null,
                'sale_date' => $data['sale_date'] ?? now(),
            ]);

            foreach ($data['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);
                $qty = (int) ($item['quantity'] ?? 1);
                $unitPrice = $item['unit_price'] ?? $product->unit_price;
                $subtotal = $qty * $unitPrice;

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_price' => $product->unit_price,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                    'tax_amount' => $item['tax_amount'] ?? 0,
                    'discount_amount' => $item['discount_amount'] ?? 0,
                ]);

                $stockBefore = $product->stock_quantity;
                $stockAfter = max(0, $stockBefore - $qty);

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
                    $customer->total_purchases = ($customer->total_purchases ?? 0) + $data['total_amount'];
                    $customer->last_purchase_at = now();
                    $customer->save();
                }
            }

            return $sale->load('items');
        });
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

    public function getByDateRange(int $businessId, string $start, string $end): Collection
    {
        return $this->saleRepository->getByDateRange($businessId, $start, $end);
    }

    public function getByShift(int $shiftId): Collection
    {
        return $this->saleRepository->getByShift($shiftId);
    }

    public function refund(int $id, array $data): Sale
    {
        return DB::transaction(function () use ($id, $data) {
            $sale = Sale::with('items')->findOrFail($id);

            foreach ($data['items'] as $refundItem) {
                $saleItem = SaleItem::findOrFail($refundItem['id']);
                $refundQty = (int) ($refundItem['quantity'] ?? $saleItem->quantity);
                $refundAmount = $refundItem['amount'] ?? ($saleItem->unit_price * $refundQty);

                $saleItem->refunded_quantity += $refundQty;
                $saleItem->refunded_amount += $refundAmount;
                $saleItem->save();

                // Restore stock
                $product = Product::find($saleItem->product_id);
                if ($product) {
                    $stockBefore = $product->stock_quantity;
                    $product->stock_quantity += $refundQty;
                    $product->save();

                    StockMovement::create([
                        'business_id' => $sale->business_id,
                        'product_id' => $product->id,
                        'sale_item_id' => $saleItem->id,
                        'type' => 'return',
                        'quantity_change' => $refundQty,
                        'stock_before' => $stockBefore,
                        'stock_after' => $product->stock_quantity,
                        'notes' => "Refund from sale {$sale->receipt_number}",
                    ]);
                }
            }

            $totalRefunded = $sale->items->sum('refunded_amount');
            $sale->payment_status = $totalRefunded >= $sale->total_amount ? 'refunded' : 'partially_refunded';
            $sale->save();

            return $sale->load('items');
        });
    }

    public function getDaily(int $businessId, ?string $date = null): Collection
    {
        $date = $date ?? now()->toDateString();
        return $this->saleRepository->getByDateRange($businessId, $date . ' 00:00:00', $date . ' 23:59:59');
    }

    protected function generateReceiptNumber(\App\Models\Business $business): string
    {
        $prefix = $business->slug ?? 'pos';
        $last = Sale::where('business_id', $business->id)
            ->where('receipt_number', 'like', $prefix . '-%')
            ->orderBy('id', 'desc')
            ->first();

        $next = $last ? (int) explode('-', $last->receipt_number)[1] + 1 : 1;
        return $prefix . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
