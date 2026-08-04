<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Authoritative storefront location / currency reference data, East Africa first.
 * Backs the storefront filter UI so options are never empty (businesses may not
 * have set location details yet). Pure reference data lives in:
 *   config/storefront-countries.php, config/storefront-cities.php, config/storefront-currencies.php
 */
class StorefrontLocations
{
    /** @return array<string, string> ISO code => display name (ordered) */
    public static function countries(): array
    {
        return (array) config('storefront-countries', []);
    }

    /** @return array<string, string> code => label (ordered) */
    public static function currencies(): array
    {
        return (array) config('storefront-currencies', []);
    }

    /** @return list<string> city names, East Africa first (ordered) */
    public static function cities(): array
    {
        return (array) config('storefront-cities', []);
    }

    /** Resolve a country ISO code => display name. */
    public static function countryName(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::countries()[strtoupper($code)] ?? null;
    }

    /** Resolve a currency code => display label. */
    public static function currencyLabel(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::currencies()[strtoupper($code)] ?? strtoupper($code);
    }
}