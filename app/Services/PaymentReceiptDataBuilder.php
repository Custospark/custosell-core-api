<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Sale;

class PaymentReceiptDataBuilder
{
    public static function buildForPayable(Invoice|Sale $payable, string $payableType): array
    {
        if ($payableType === 'invoice' && $payable instanceof Invoice) {
            return self::fromInvoice($payable);
        }

        if ($payable instanceof Sale) {
            return self::fromSale($payable);
        }

        return self::empty();
    }

    protected static function fromSale(Sale $sale): array
    {
        $sale->loadMissing('saleItems', 'customer');

        $items = $sale->saleItems->map(fn ($item) => [
            'name' => $item->product_name,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'subtotal' => (float) $item->subtotal,
            'discount' => (float) ($item->discount_amount ?? 0),
            'tax_amount' => (float) ($item->tax_amount ?? 0),
            'refunded_quantity' => (int) ($item->refunded_quantity ?? 0),
            'refunded_amount' => (float) ($item->refunded_amount ?? 0),
        ])->values()->all();

        $totalRefunded = $sale->saleItems->sum(fn ($i) => (float) $i->refunded_amount);
        $billTotal = max(0, (float) $sale->total_amount - $totalRefunded);

        return [
            'lineItems' => $items,
            'subtotal' => (float) $sale->subtotal,
            'discount' => (float) ($sale->discount_amount ?? 0),
            'tax_total' => (float) ($sale->tax_total ?? 0),
            'total_refunded' => (float) $totalRefunded,
            'bill_total' => $billTotal,
            'customer_name' => $sale->customer?->name,
        ];
    }

    protected static function fromInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing('items', 'customer');

        $items = $invoice->items->map(fn ($item) => [
            'name' => $item->description,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'subtotal' => (float) $item->subtotal,
            'discount' => 0.0,
            'tax_amount' => 0.0,
            'refunded_quantity' => 0,
            'refunded_amount' => 0.0,
        ])->values()->all();

        $linesSubtotal = array_sum(array_column($items, 'subtotal'));

        return [
            'lineItems' => $items,
            'subtotal' => $linesSubtotal > 0 ? $linesSubtotal : (float) $invoice->subtotal,
            'discount' => 0.0,
            'tax_total' => (float) ($invoice->tax_total ?? 0),
            'total_refunded' => 0.0,
            'bill_total' => (float) $invoice->total_amount,
            'customer_name' => $invoice->customer?->name,
        ];
    }

    protected static function empty(): array
    {
        return [
            'lineItems' => [],
            'subtotal' => 0.0,
            'discount' => 0.0,
            'tax_total' => 0.0,
            'total_refunded' => 0.0,
            'bill_total' => 0.0,
            'customer_name' => null,
        ];
    }
}
