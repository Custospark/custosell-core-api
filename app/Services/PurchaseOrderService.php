<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Services\Contracts\PurchaseOrderServiceInterface;
use App\Services\DocumentNumberGenerator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService implements PurchaseOrderServiceInterface
{
    public function getAllForBuyer(int $buyerBusinessId, array $filters = []): Collection
    {
        $query = PurchaseOrder::query()
            ->where('buyer_business_id', $buyerBusinessId)
            ->with(['items', 'sellerBusiness', 'buyerBusiness'])
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get();
    }

    public function getIncomingForSeller(int $sellerBusinessId, array $filters = []): Collection
    {
        $query = PurchaseOrder::query()
            ->where('seller_business_id', $sellerBusinessId)
            ->where('status', '!=', PurchaseOrder::STATUS_DRAFT)
            ->with(['items', 'buyerBusiness', 'sellerBusiness'])
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get();
    }

    public function getVisibleForBusiness(int $id, int $businessId): ?PurchaseOrder
    {
        return PurchaseOrder::query()
            ->where('id', $id)
            ->where(function ($builder) use ($businessId) {
                $builder->where('buyer_business_id', $businessId)
                    ->orWhere('seller_business_id', $businessId);
            })
            ->with(['items.product', 'items.receivedProduct', 'buyerBusiness', 'sellerBusiness', 'createdBy'])
            ->first();
    }

    public function create(int $buyerBusinessId, int $userId, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($buyerBusinessId, $userId, $data) {
            $sellerBusinessId = (int) $data['seller_business_id'];

            if ($sellerBusinessId === $buyerBusinessId) {
                throw ValidationException::withMessages([
                    'seller_business_id' => ['You cannot raise a purchase order against your own business.'],
                ]);
            }

            $seller = Business::find($sellerBusinessId);
            if (! $seller || ! $seller->isOpenForSupply()) {
                throw ValidationException::withMessages([
                    'seller_business_id' => ['This business is not open for supply.'],
                ]);
            }

            $lines = $this->buildLines($sellerBusinessId, $data['items'] ?? []);
            $totals = $this->sumLines($lines, (float) ($data['discount_amount'] ?? 0), (float) ($data['tax_total'] ?? 0));

            $buyer = Business::findOrFail($buyerBusinessId);

            $po = PurchaseOrder::create([
                'buyer_business_id' => $buyerBusinessId,
                'seller_business_id' => $sellerBusinessId,
                'created_by' => $userId,
                'po_number' => $this->generatePoNumber($buyer),
                'status' => PurchaseOrder::STATUS_DRAFT,
                'payment_status' => PurchaseOrder::PAYMENT_STATUS_UNPAID,
                'subtotal' => $totals['subtotal'],
                'tax_total' => $totals['tax_total'],
                'discount_amount' => $totals['discount_amount'],
                'total_amount' => $totals['total_amount'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->replaceItems($po, $lines);

            return $po->fresh(['items', 'sellerBusiness', 'buyerBusiness']);
        });
    }

    public function update(int $id, int $buyerBusinessId, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $buyerBusinessId, $data) {
            $po = PurchaseOrder::where('buyer_business_id', $buyerBusinessId)->findOrFail($id);

            if (! $po->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => ['Only draft purchase orders can be edited.'],
                ]);
            }

            if (array_key_exists('notes', $data)) {
                $po->notes = $data['notes'];
            }

            if (isset($data['items'])) {
                $lines = $this->buildLines((int) $po->seller_business_id, $data['items']);
                $totals = $this->sumLines(
                    $lines,
                    (float) ($data['discount_amount'] ?? $po->discount_amount),
                    (float) ($data['tax_total'] ?? $po->tax_total),
                );
                $po->subtotal = $totals['subtotal'];
                $po->tax_total = $totals['tax_total'];
                $po->discount_amount = $totals['discount_amount'];
                $po->total_amount = $totals['total_amount'];
                $this->replaceItems($po, $lines);
            } elseif (isset($data['discount_amount']) || isset($data['tax_total'])) {
                $lines = $po->items->map(fn (PurchaseOrderItem $item) => [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'product_sku' => $item->product_sku,
                    'unit_price' => (float) $item->unit_price,
                    'quantity' => (int) $item->quantity,
                    'subtotal' => (float) $item->subtotal,
                ])->all();
                $totals = $this->sumLines(
                    $lines,
                    (float) ($data['discount_amount'] ?? $po->discount_amount),
                    (float) ($data['tax_total'] ?? $po->tax_total),
                );
                $po->subtotal = $totals['subtotal'];
                $po->tax_total = $totals['tax_total'];
                $po->discount_amount = $totals['discount_amount'];
                $po->total_amount = $totals['total_amount'];
            }

            $po->save();

            return $po->fresh(['items', 'sellerBusiness', 'buyerBusiness']);
        });
    }

    public function submit(int $id, int $buyerBusinessId): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $buyerBusinessId) {
            $po = PurchaseOrder::where('buyer_business_id', $buyerBusinessId)->lockForUpdate()->findOrFail($id);

            if (! $po->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => ['Only draft purchase orders can be submitted.'],
                ]);
            }

            if ($po->items()->count() < 1) {
                throw ValidationException::withMessages([
                    'items' => ['A purchase order must include at least one item before submitting.'],
                ]);
            }

            $po->status = PurchaseOrder::STATUS_SUBMITTED;
            $po->submitted_at = now();
            $po->save();

            return $po->fresh(['items', 'sellerBusiness', 'buyerBusiness']);
        });
    }

    public function cancel(int $id, int $buyerBusinessId): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $buyerBusinessId) {
            $po = PurchaseOrder::where('buyer_business_id', $buyerBusinessId)->lockForUpdate()->findOrFail($id);

            if (! in_array($po->status, [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SUBMITTED], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only draft or submitted purchase orders can be cancelled.'],
                ]);
            }

            $po->status = PurchaseOrder::STATUS_CANCELLED;
            $po->cancelled_at = now();
            $po->save();

            return $po->fresh(['items', 'sellerBusiness', 'buyerBusiness']);
        });
    }

    public function accept(int $id, int $sellerBusinessId): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $sellerBusinessId) {
            $po = PurchaseOrder::where('seller_business_id', $sellerBusinessId)->lockForUpdate()->findOrFail($id);

            if (! $po->isSubmitted()) {
                throw ValidationException::withMessages([
                    'status' => ['Only submitted purchase orders can be accepted.'],
                ]);
            }

            $po->status = PurchaseOrder::STATUS_ACCEPTED;
            $po->accepted_at = now();
            $po->save();

            return $po->fresh(['items', 'sellerBusiness', 'buyerBusiness']);
        });
    }

    public function reject(int $id, int $sellerBusinessId, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $sellerBusinessId, $reason) {
            $po = PurchaseOrder::where('seller_business_id', $sellerBusinessId)->lockForUpdate()->findOrFail($id);

            if (! $po->isSubmitted()) {
                throw ValidationException::withMessages([
                    'status' => ['Only submitted purchase orders can be rejected.'],
                ]);
            }

            $po->status = PurchaseOrder::STATUS_REJECTED;
            $po->rejection_reason = $reason;
            $po->rejected_at = now();
            $po->save();

            return $po->fresh(['items', 'sellerBusiness', 'buyerBusiness']);
        });
    }

    public function fulfill(int $id, int $sellerBusinessId, ?int $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $sellerBusinessId, $userId) {
            $po = PurchaseOrder::where('seller_business_id', $sellerBusinessId)
                ->lockForUpdate()
                ->findOrFail($id);

            if (! $po->isAccepted()) {
                throw ValidationException::withMessages([
                    'status' => ['Only accepted purchase orders can be fulfilled.'],
                ]);
            }

            $items = $po->items()->get();
            $products = [];

            foreach ($items as $item) {
                $product = Product::where('id', $item->product_id)
                    ->where('business_id', $sellerBusinessId)
                    ->lockForUpdate()
                    ->first();

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => ["Product for line #{$item->id} is no longer available."],
                    ]);
                }

                if ((int) $product->stock_quantity < (int) $item->quantity) {
                    throw ValidationException::withMessages([
                        'items' => ["Insufficient stock for \"{$product->name}\" to fulfill this order."],
                    ]);
                }

                $products[$item->id] = $product;
            }

            foreach ($items as $item) {
                $product = $products[$item->id];
                $stockBefore = (int) $product->stock_quantity;
                $stockAfter = $stockBefore - (int) $item->quantity;

                StockMovement::create([
                    'business_id' => $sellerBusinessId,
                    'product_id' => $product->id,
                    'type' => 'sale',
                    'quantity_change' => -1 * (int) $item->quantity,
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockAfter,
                    'reference' => $po->po_number,
                    'notes' => 'Fulfilled purchase order '.$po->po_number,
                    'created_by' => $userId,
                ]);

                $product->stock_quantity = $stockAfter;
                $product->save();

                $item->quantity_fulfilled = $item->quantity;
                $item->save();
            }

            $po->status = PurchaseOrder::STATUS_FULFILLED;
            $po->fulfilled_at = now();
            $po->save();

            return $po->fresh(['items', 'sellerBusiness', 'buyerBusiness']);
        });
    }

    public function receive(int $id, int $buyerBusinessId, ?int $userId, array $itemMappings): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $buyerBusinessId, $userId, $itemMappings) {
            $po = PurchaseOrder::where('buyer_business_id', $buyerBusinessId)
                ->lockForUpdate()
                ->findOrFail($id);

            if ($po->status !== PurchaseOrder::STATUS_FULFILLED) {
                throw ValidationException::withMessages([
                    'status' => ['Only fulfilled purchase orders can be received.'],
                ]);
            }

            $items = $po->items()->get()->keyBy('id');

            if (count($itemMappings) < $items->count()) {
                throw ValidationException::withMessages([
                    'items' => ['Every line on the purchase order must be mapped to a local product to receive it.'],
                ]);
            }

            $resolved = [];
            foreach ($itemMappings as $mapping) {
                $item = $items->get($mapping['id']);
                if (! $item) {
                    throw ValidationException::withMessages([
                        'items' => ["Line #{$mapping['id']} does not belong to this purchase order."],
                    ]);
                }

                $localProduct = Product::where('id', $mapping['product_id'])
                    ->where('business_id', $buyerBusinessId)
                    ->lockForUpdate()
                    ->first();

                if (! $localProduct) {
                    throw ValidationException::withMessages([
                        'items' => ["The selected local product for line #{$item->id} was not found in your catalog."],
                    ]);
                }

                $resolved[] = ['item' => $item, 'product' => $localProduct];
            }

            foreach ($resolved as $pair) {
                /** @var PurchaseOrderItem $item */
                $item = $pair['item'];
                /** @var Product $product */
                $product = $pair['product'];

                $stockBefore = (int) $product->stock_quantity;
                $stockAfter = $stockBefore + (int) $item->quantity;

                StockMovement::create([
                    'business_id' => $buyerBusinessId,
                    'product_id' => $product->id,
                    'type' => 'purchase',
                    'quantity_change' => (int) $item->quantity,
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockAfter,
                    'reference' => $po->po_number,
                    'notes' => 'Received purchase order '.$po->po_number,
                    'created_by' => $userId,
                ]);

                $product->stock_quantity = $stockAfter;
                $product->save();

                $item->received_product_id = $product->id;
                $item->save();
            }

            $po->status = PurchaseOrder::STATUS_RECEIVED;
            $po->received_at = now();
            $po->save();

            return $po->fresh(['items.receivedProduct', 'sellerBusiness', 'buyerBusiness']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function buildLines(int $sellerBusinessId, array $items): array
    {
        if (count($items) < 1) {
            throw ValidationException::withMessages([
                'items' => ['A purchase order must include at least one item.'],
            ]);
        }

        $lines = [];
        foreach ($items as $index => $item) {
            $product = Product::where('id', $item['product_id'] ?? null)
                ->where('business_id', $sellerBusinessId)
                ->where('type', Product::TYPE_PRODUCT)
                ->where('is_active', true)
                ->where('listed_for_supply', true)
                ->first();

            if (! $product) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => ['This product is not available for supply from the selected business.'],
                ]);
            }

            $qty = max(1, (int) ($item['quantity'] ?? 1));

            if ($qty < (int) $product->supply_min_qty) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => ["\"{$product->name}\" requires a minimum order quantity of {$product->supply_min_qty}."],
                ]);
            }

            $unitPrice = $product->supplyUnitPrice();

            $lines[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'unit_price' => $unitPrice,
                'quantity' => $qty,
                'subtotal' => round($unitPrice * $qty, 2),
            ];
        }

        return $lines;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{subtotal: float, tax_total: float, discount_amount: float, total_amount: float}
     */
    protected function sumLines(array $lines, float $discountAmount, float $taxTotal): array
    {
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $subtotal += (float) $line['subtotal'];
        }

        $discount = max(0, $discountAmount);
        $tax = max(0, $taxTotal);
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
    protected function replaceItems(PurchaseOrder $po, array $lines): void
    {
        $po->items()->delete();
        foreach ($lines as $line) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product_id' => $line['product_id'],
                'product_name' => $line['product_name'],
                'product_sku' => $line['product_sku'],
                'unit_price' => $line['unit_price'],
                'quantity' => $line['quantity'],
                'quantity_fulfilled' => 0,
                'subtotal' => $line['subtotal'],
            ]);
        }
    }

    protected function generatePoNumber(Business $buyer): string
    {
        return DocumentNumberGenerator::purchaseOrderNumber($buyer, PurchaseOrder::class, 'po_number');
    }
}
