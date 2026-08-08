<?php

namespace App\Services\Estimate\Concerns;

use App\Models\Estimate;
use App\Models\EstimateLineItem;
use Illuminate\Database\Eloquent\Collection;

trait ManagesEstimateLineItems
{
    /**
     * @return list<array<string, mixed>>
     */
    protected function lineItemsData(Collection $items): array
    {
        return $items->map(fn (EstimateLineItem $item) => [
            'product_id' => $item->product_id,
            'sort_order' => $item->sort_order,
            'type' => $item->type,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_cost' => $item->unit_cost,
            'unit_price' => $item->unit_price,
            'markup_type' => $item->markup_type,
            'markup_value' => $item->markup_value,
            'is_billable' => $item->is_billable,
        ])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $lineItems
     * @return array<string, float>
     */
    protected function calculateTotals(array $lineItems, array $data): array
    {
        $subtotal = 0;
        $costSubtotal = 0;

        foreach ($lineItems as $item) {
            $calculated = $this->calculateLineItem($item);
            if (($item['is_billable'] ?? true) !== false) {
                $subtotal += $calculated['total_price'];
            }
            $costSubtotal += $calculated['total_cost'];
        }

        $discountType = $data['discount_type'] ?? null;
        $discountValue = (float) ($data['discount_value'] ?? 0);
        $discountAmount = 0;

        if ($discountType === 'percent' && $discountValue > 0) {
            $discountAmount = round($subtotal * ($discountValue / 100), 2);
        } elseif ($discountType === 'fixed' && $discountValue > 0) {
            $discountAmount = min($discountValue, $subtotal);
        }

        $taxable = max(0, $subtotal - $discountAmount);
        $taxRate = (float) ($data['tax_rate'] ?? 0);
        $taxTotal = round($taxable * ($taxRate / 100), 2);
        $total = $taxable + $taxTotal;

        $revenue = $taxable;
        $grossProfit = round($revenue - $costSubtotal, 2);
        $marginPercent = $revenue > 0
            ? round(($grossProfit / $revenue) * 100, 2)
            : 0;

        return [
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discountAmount, 2),
            'tax_total' => $taxTotal,
            'total' => round($total, 2),
            'cost_subtotal' => round($costSubtotal, 2),
            'gross_profit' => $grossProfit,
            'margin_percent' => $marginPercent,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{unit_price: float, total_cost: float, total_price: float}
     */
    protected function calculateLineItem(array $item): array
    {
        $qty = (float) ($item['quantity'] ?? 1);
        $unitCost = (float) ($item['unit_cost'] ?? 0);
        $markupType = $item['markup_type'] ?? 'none';
        $markupValue = (float) ($item['markup_value'] ?? 0);

        $unitPrice = match ($markupType) {
            'percent' => $unitCost * (1 + ($markupValue / 100)),
            'fixed' => $unitCost + $markupValue,
            default => (float) ($item['unit_price'] ?? 0),
        };

        return [
            'unit_price' => round($unitPrice, 2),
            'total_cost' => round($qty * $unitCost, 2),
            'total_price' => round($qty * $unitPrice, 2),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lineItems
     */
    protected function syncLineItems(Estimate $estimate, array $lineItems): void
    {
        foreach ($lineItems as $index => $item) {
            $calculated = $this->calculateLineItem($item);

            EstimateLineItem::create([
                'estimate_id' => $estimate->id,
                'product_id' => $item['product_id'] ?? null,
                'sort_order' => $item['sort_order'] ?? $index,
                'type' => $item['type'] ?? 'other',
                'description' => $item['description'],
                'quantity' => $item['quantity'] ?? 1,
                'unit_cost' => $item['unit_cost'] ?? 0,
                'unit_price' => $calculated['unit_price'],
                'markup_type' => $item['markup_type'] ?? 'none',
                'markup_value' => $item['markup_value'] ?? 0,
                'total_cost' => $calculated['total_cost'],
                'total_price' => $calculated['total_price'],
                'is_billable' => $item['is_billable'] ?? true,
            ]);
        }
    }
}