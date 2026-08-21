<?php

namespace App\Support;

/**
 * Selling/pricing units for products.
 *
 * Units are grouped by family and classified as either:
 *  - decimal-capable (mass / volume), where cashiers pick fractional
 *    quantities (0.5 kg, 0.25 L) and the price is unit_price x quantity; or
 *  - integer (pieces), where quantities are whole numbers.
 *
 * Base unit normalisation keeps family members on one stock scale (kg and g
 * both resolve to kg; litre and ml to litre), so 0.5 kg and 500 g cannot
 * drift into separate stock lines.
 */
class PricingUnits
{
    public const FAMILY_MASS = 'mass';

    public const FAMILY_VOLUME = 'volume';

    public const FAMILY_PIECE = 'piece';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            'kg', 'g', 'tonne',
            'litre', 'ml',
            'piece', 'box', 'dozen', 'packet', 'bag', 'bundle', 'carton', 'pair',
        ];
    }

    public static function isKnown(string $unit): bool
    {
        return in_array(self::normaliseKey($unit), self::all(), true);
    }

    public static function family(string $unit): string
    {
        return match (self::normaliseKey($unit)) {
            'kg', 'g', 'tonne' => self::FAMILY_MASS,
            'litre', 'ml' => self::FAMILY_VOLUME,
            default => self::FAMILY_PIECE,
        };
    }

    /** Mass/volume units support fractional quantities; piece units do not. */
    public static function supportsDecimalQuantity(string $unit): bool
    {
        return in_array(self::family($unit), [self::FAMILY_MASS, self::FAMILY_VOLUME], true);
    }

    /** Base unit for a family, used to keep stock on one scale. */
    public static function baseUnit(string $unit): string
    {
        return match (self::normaliseKey($unit)) {
            'g', 'tonne' => 'kg',
            'ml' => 'litre',
            default => self::normaliseKey($unit) ?: 'piece',
        };
    }

    /** Normalise whitespace/case so "Kg", "LITRE", "1L bottle" match sensibly. */
    public static function normaliseKey(string $unit): string
    {
        $key = strtolower(trim($unit));
        $key = str_replace([' '], '_', $key);

        return $key;
    }

    /** Presentable label, e.g. "Kg", "Litre", "Piece". */
    public static function label(string $unit): string
    {
        return match (self::normaliseKey($unit)) {
            'kg' => 'Kg',
            'g' => 'g',
            'tonne' => 'Tonne',
            'litre' => 'Litre',
            'ml' => 'mL',
            'piece' => 'Piece',
            'box' => 'Box',
            'dozen' => 'Dozen',
            'packet' => 'Packet',
            'bag' => 'Bag',
            'bundle' => 'Bundle',
            'carton' => 'Carton',
            'pair' => 'Pair',
            default => ucfirst(trim($unit)),
        };
    }
}