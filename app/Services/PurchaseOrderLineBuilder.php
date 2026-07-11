<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Validation\ValidationException;

class PurchaseOrderLineBuilder
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function buildLines(int $sellerBusinessId, array $items): array
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
    public function sumLines(array $lines, float $discountAmount, float $taxTotal): array
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
    public function replaceItems(PurchaseOrder $po, array $lines): void
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

    public function generatePoNumber(Business $buyer): string
    {
        return DocumentNumberGenerator::purchaseOrderNumber($buyer, PurchaseOrder::class, 'po_number');
    }
}
