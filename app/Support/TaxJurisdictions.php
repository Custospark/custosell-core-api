<?php

namespace App\Support;

class TaxJurisdictions
{
    /** @var array<string, array{name: string, default_vat_rate: float, filing_authority: string|null}> */
    private const REGIONS = [
        'UG' => ['name' => 'Uganda', 'default_vat_rate' => 18.0, 'filing_authority' => 'URA'],
        'KE' => ['name' => 'Kenya', 'default_vat_rate' => 16.0, 'filing_authority' => 'KRA'],
        'TZ' => ['name' => 'Tanzania', 'default_vat_rate' => 18.0, 'filing_authority' => 'TRA'],
        'RW' => ['name' => 'Rwanda', 'default_vat_rate' => 18.0, 'filing_authority' => 'RRA'],
        'NG' => ['name' => 'Nigeria', 'default_vat_rate' => 7.5, 'filing_authority' => 'FIRS'],
        'GH' => ['name' => 'Ghana', 'default_vat_rate' => 15.0, 'filing_authority' => 'GRA'],
        'ZA' => ['name' => 'South Africa', 'default_vat_rate' => 15.0, 'filing_authority' => 'SARS'],
        'OTHER' => ['name' => 'Other', 'default_vat_rate' => 0.0, 'filing_authority' => null],
    ];

    public static function label(?string $code): string
    {
        if (!$code) {
            return 'Not set';
        }

        return self::REGIONS[$code]['name'] ?? $code;
    }

    public static function filingAuthority(?string $code): ?string
    {
        if (!$code || !isset(self::REGIONS[$code])) {
            return null;
        }

        return self::REGIONS[$code]['filing_authority'];
    }

    public static function filingHint(?string $code): string
    {
        $authority = self::filingAuthority($code);
        if (!$authority) {
            return 'Submit through your tax authority\'s online portal.';
        }

        return "Submit through the {$authority} online portal or your jurisdiction's filing channel.";
    }

    public static function defaultVatRate(?string $code): float
    {
        if (!$code || !isset(self::REGIONS[$code])) {
            return 18.0;
        }

        $rate = self::REGIONS[$code]['default_vat_rate'];

        return $rate > 0 ? $rate : 18.0;
    }
}
