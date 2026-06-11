<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Product;

class TaxEngine
{
    public const REGIME_NONE = 'none';

    public const REGIME_VAT_REGISTERED = 'vat_registered';

    public const CLASS_STANDARD = 'standard';

    public const CLASS_EXEMPT = 'exempt';

    public const CLASS_ZERO_RATED = 'zero_rated';

    public function isTaxEnabled(Business $business): bool
    {
        return ($business->tax_regime ?? self::REGIME_NONE) === self::REGIME_VAT_REGISTERED;
    }

    public function resolveRate(Business $business, Product $product): float
    {
        if (!$this->isTaxEnabled($business)) {
            return 0.0;
        }

        $taxClass = $product->tax_class ?? self::CLASS_STANDARD;
        if (in_array($taxClass, [self::CLASS_EXEMPT, self::CLASS_ZERO_RATED], true)) {
            return 0.0;
        }

        $rate = (float) ($product->tax_percentage ?? 0);
        if ($rate <= 0) {
            $rate = (float) ($business->default_vat_rate ?? 18);
        }

        return max(0.0, $rate);
    }

    /**
     * @return array{net: float, tax: float, gross: float, rate: float, tax_class: string}
     */
    public function computeLine(
        Business $business,
        Product $product,
        int $quantity,
        float $unitPrice,
        float $lineDiscount = 0,
    ): array {
        $rate = $this->resolveRate($business, $product);
        $taxClass = $product->tax_class ?? self::CLASS_STANDARD;
        $grossBeforeDiscount = $quantity * $unitPrice;
        $taxableBase = max(0, $grossBeforeDiscount - $lineDiscount);

        if ($rate <= 0) {
            $rounded = round($taxableBase, 2);

            return [
                'net' => $rounded,
                'tax' => 0.0,
                'gross' => $rounded,
                'rate' => 0.0,
                'tax_class' => $taxClass,
            ];
        }

        if ($business->prices_include_tax) {
            $tax = round($taxableBase * $rate / (100 + $rate), 2);
            $net = round($taxableBase - $tax, 2);

            return [
                'net' => $net,
                'tax' => $tax,
                'gross' => round($taxableBase, 2),
                'rate' => $rate,
                'tax_class' => $taxClass,
            ];
        }

        $net = round($taxableBase, 2);
        $tax = round($net * $rate / 100, 2);

        return [
            'net' => $net,
            'tax' => $tax,
            'gross' => round($net + $tax, 2),
            'rate' => $rate,
            'tax_class' => $taxClass,
        ];
    }

    /**
     * @param  list<array{product: Product, quantity: int, unit_price: float, discount_amount?: float}>  $items
     * @return array{
     *   lines: list<array{product: Product, quantity: int, unit_price: float, net: float, tax: float, gross: float, rate: float, tax_class: string, subtotal: float, tax_amount: float}>,
     *   subtotal: float,
     *   tax_total: float,
     *   discount_amount: float,
     *   total_amount: float
     * }
     */
    public function computeSale(Business $business, array $items, float $saleDiscount = 0): array
    {
        $rawLines = [];
        $sumNet = 0.0;
        $sumTax = 0.0;
        $sumGross = 0.0;

        foreach ($items as $item) {
            $product = $item['product'];
            $quantity = (int) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];
            $lineDiscount = (float) ($item['discount_amount'] ?? 0);
            $computed = $this->computeLine($business, $product, $quantity, $unitPrice, $lineDiscount);

            $rawLines[] = array_merge($computed, [
                'product' => $product,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $computed['net'],
                'tax_amount' => $computed['tax'],
            ]);

            $sumNet += $computed['net'];
            $sumTax += $computed['tax'];
            $sumGross += $computed['gross'];
        }

        $saleDiscount = max(0, round($saleDiscount, 2));
        if ($saleDiscount > 0 && $sumGross > 0) {
            $discountRatio = min(1, $saleDiscount / $sumGross);
            $sumNet = round($sumNet * (1 - $discountRatio), 2);
            $sumTax = round($sumTax * (1 - $discountRatio), 2);
            $sumGross = round($sumGross - $saleDiscount, 2);

            foreach ($rawLines as &$line) {
                $line['subtotal'] = round($line['net'] * (1 - $discountRatio), 2);
                $line['tax_amount'] = round($line['tax'] * (1 - $discountRatio), 2);
            }
            unset($line);
        }

        $totalAmount = round(max(0, $sumNet + $sumTax), 2);

        return [
            'lines' => $rawLines,
            'subtotal' => round($sumNet, 2),
            'tax_total' => round(max(0, $sumTax), 2),
            'discount_amount' => $saleDiscount,
            'total_amount' => $totalAmount,
        ];
    }

    public function computeLineTaxRefund(float $lineTaxAmount, int $lineQuantity, int $refundQuantity): float
    {
        if ($lineQuantity <= 0 || $refundQuantity <= 0 || $lineTaxAmount <= 0) {
            return 0.0;
        }

        return round($lineTaxAmount * ($refundQuantity / $lineQuantity), 2);
    }
}
