<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shift;
use App\Services\Contracts\OrderServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService implements OrderServiceInterface
{
    public function getAll(int $businessId, array $filters = []): Collection
    {
        $query = Order::query()
            ->where('business_id', $businessId)
            ->with(['items', 'customer', 'user', 'sale'])
            ->orderByDesc('held_at')
            ->orderByDesc('id');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            $query->where(function ($builder) use ($q) {
                $builder->where('order_number', 'like', "%{$q}%")
                    ->orWhere('customer_name', 'like', "%{$q}%")
                    ->orWhere('notes', 'like', "%{$q}%");
            });
        }

        return $query->get();
    }

    public function getById(int $id): ?Order
    {
        return Order::with(['items.product', 'customer', 'user', 'sale', 'shift'])->find($id);
    }

    public function create(int $businessId, int $userId, array $data): Order
    {
        return DB::transaction(function () use ($businessId, $userId, $data) {
            $business = Business::findOrFail($businessId);
            $lines = $this->normalizeLines($data['items'] ?? []);
            $totals = $this->sumLines($lines, (float) ($data['discount_amount'] ?? 0), (float) ($data['tax_total'] ?? 0));

            $order = Order::create([
                'business_id' => $businessId,
                'user_id' => $userId,
                'customer_id' => $data['customer_id'] ?? null,
                'shift_id' => $this->resolveShiftId($businessId, $userId, $data['shift_id'] ?? null),
                'order_number' => $this->generateOrderNumber($business),
                'status' => Order::STATUS_OPEN,
                'customer_name' => $data['customer_name'] ?? null,
                'subtotal' => $totals['subtotal'],
                'tax_total' => $totals['tax_total'],
                'discount_amount' => $totals['discount_amount'],
                'total_amount' => $totals['total_amount'],
                'notes' => $data['notes'] ?? null,
                'held_at' => now(),
            ]);

            $this->replaceItems($order, $lines);

            return $order->load(['items', 'customer', 'user', 'sale']);
        });
    }

    public function update(int $id, int $businessId, array $data): Order
    {
        return DB::transaction(function () use ($id, $businessId, $data) {
            $order = Order::where('business_id', $businessId)->find($id);
            if (!$order) {
                throw new \RuntimeException('Order not found');
            }
            if (!$order->isOpen()) {
                throw ValidationException::withMessages([
                    'status' => ['Only open orders can be updated.'],
                ]);
            }

            if (array_key_exists('customer_name', $data)) {
                $order->customer_name = $data['customer_name'];
            }
            if (array_key_exists('notes', $data)) {
                $order->notes = $data['notes'];
            }
            if (array_key_exists('customer_id', $data)) {
                $order->customer_id = $data['customer_id'];
            }
            if (array_key_exists('shift_id', $data)) {
                $order->shift_id = $this->resolveShiftId($businessId, (int) $order->user_id, $data['shift_id']);
            }

            if (isset($data['items'])) {
                $lines = $this->normalizeLines($data['items']);
                $totals = $this->sumLines(
                    $lines,
                    (float) ($data['discount_amount'] ?? $order->discount_amount),
                    (float) ($data['tax_total'] ?? $order->tax_total),
                );
                $order->subtotal = $totals['subtotal'];
                $order->tax_total = $totals['tax_total'];
                $order->discount_amount = $totals['discount_amount'];
                $order->total_amount = $totals['total_amount'];
                $this->replaceItems($order, $lines);
            } elseif (isset($data['discount_amount']) || isset($data['tax_total'])) {
                $lines = $order->items->map(fn (OrderItem $item) => [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'product_price' => (float) $item->product_price,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount_amount' => (float) $item->discount_amount,
                    'tax_amount' => (float) $item->tax_amount,
                    'subtotal' => (float) $item->subtotal,
                ])->all();
                $totals = $this->sumLines(
                    $lines,
                    (float) ($data['discount_amount'] ?? $order->discount_amount),
                    (float) ($data['tax_total'] ?? $order->tax_total),
                );
                $order->subtotal = $totals['subtotal'];
                $order->tax_total = $totals['tax_total'];
                $order->discount_amount = $totals['discount_amount'];
                $order->total_amount = $totals['total_amount'];
            }

            $order->held_at = now();
            $order->save();

            return $order->fresh(['items', 'customer', 'user', 'sale']);
        });
    }

    public function cancel(int $id, int $businessId): Order
    {
        $order = Order::where('business_id', $businessId)->find($id);
        if (!$order) {
            throw new \RuntimeException('Order not found');
        }
        if (!$order->isOpen()) {
            throw ValidationException::withMessages([
                'status' => ['Only open orders can be cancelled.'],
            ]);
        }

        $order->status = Order::STATUS_CANCELLED;
        $order->save();

        return $order->fresh(['items', 'customer', 'user', 'sale']);
    }

    public function assertOrderOpenForSale(int $orderId, int $businessId): void
    {
        $order = Order::where('business_id', $businessId)->lockForUpdate()->find($orderId);
        if (!$order) {
            throw ValidationException::withMessages([
                'order_id' => ['Order not found.'],
            ]);
        }
        if (!$order->isOpen()) {
            throw ValidationException::withMessages([
                'order_id' => ['This order is no longer open and cannot be completed again.'],
            ]);
        }
    }

    public function completeFromSale(int $orderId, int $businessId, int $saleId): Order
    {
        $this->assertOrderOpenForSale($orderId, $businessId);

        $order = Order::where('business_id', $businessId)->lockForUpdate()->findOrFail($orderId);
        $order->status = Order::STATUS_COMPLETED;
        $order->sale_id = $saleId;
        $order->save();

        return $order;
    }

    public function markInvoicedForSale(int $saleId): void
    {
        $sale = Sale::find($saleId);
        if (!$sale || !$sale->order_id) {
            return;
        }

        $order = Order::find($sale->order_id);
        if (!$order) {
            return;
        }

        if ($order->status === Order::STATUS_COMPLETED || $order->status === Order::STATUS_INVOICED) {
            $order->status = Order::STATUS_INVOICED;
            if (!$order->sale_id) {
                $order->sale_id = $saleId;
            }
            $order->save();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeLines(array $items): array
    {
        if (count($items) < 1) {
            throw ValidationException::withMessages([
                'items' => ['An order must include at least one item.'],
            ]);
        }

        $lines = [];
        foreach ($items as $item) {
            $product = !empty($item['product_id']) ? Product::find($item['product_id']) : null;
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $unitPrice = (float) ($item['unit_price'] ?? $product?->unit_price ?? 0);
            $discount = (float) ($item['discount_amount'] ?? 0);
            $tax = (float) ($item['tax_amount'] ?? 0);
            $lineSubtotal = isset($item['subtotal'])
                ? (float) $item['subtotal']
                : max(0, ($unitPrice * $qty) - $discount);

            $lines[] = [
                'product_id' => $product?->id ?? ($item['product_id'] ?? null),
                'product_name' => $item['product_name'] ?? $product?->name ?? 'Item',
                'product_price' => (float) ($item['product_price'] ?? $product?->unit_price ?? $unitPrice),
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'subtotal' => $lineSubtotal,
            ];
        }

        return $lines;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{subtotal: float, tax_total: float, discount_amount: float, total_amount: float}
     */
    protected function sumLines(array $lines, float $orderDiscount, float $taxTotal): array
    {
        $subtotal = 0.0;
        $lineTax = 0.0;
        foreach ($lines as $line) {
            $subtotal += (float) $line['subtotal'];
            $lineTax += (float) $line['tax_amount'];
        }

        $tax = $taxTotal > 0 ? $taxTotal : $lineTax;
        $discount = max(0, $orderDiscount);
        $total = max(0, $subtotal + $tax - $discount);

        return [
            'subtotal' => round($subtotal, 2),
            'tax_total' => round($tax, 2),
            'discount_amount' => round($discount, 2),
            'total_amount' => round($total, 2),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function replaceItems(Order $order, array $lines): void
    {
        $order->items()->delete();
        foreach ($lines as $line) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $line['product_id'],
                'product_name' => $line['product_name'],
                'product_price' => $line['product_price'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'subtotal' => $line['subtotal'],
                'tax_amount' => $line['tax_amount'],
                'discount_amount' => $line['discount_amount'],
            ]);
        }
    }

    protected function generateOrderNumber(Business $business): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $business->name ?? 'ORD') ?: 'ORD', 0, 4));
        $date = now()->format('ymd');

        do {
            $number = sprintf('%s-%s-%s', $prefix, $date, strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)));
        } while (Order::where('business_id', $business->id)->where('order_number', $number)->exists());

        return $number;
    }

    protected function resolveShiftId(int $businessId, int $userId, mixed $shiftId): ?int
    {
        if ($shiftId) {
            $shift = Shift::where('business_id', $businessId)->find($shiftId);
            return $shift?->id;
        }

        $open = Shift::where('business_id', $businessId)
            ->where('user_id', $userId)
            ->whereNull('clock_out')
            ->latest('id')
            ->first();

        return $open?->id;
    }
}
