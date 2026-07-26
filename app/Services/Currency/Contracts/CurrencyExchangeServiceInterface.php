<?php

declare(strict_types=1);

namespace App\Services\Currency\Contracts;

interface CurrencyExchangeServiceInterface
{
    public function convert(float $amount, string $to, string $from = 'USD'): ?float;

    public function getExchangeRate(string $from, string $to): ?float;
}
