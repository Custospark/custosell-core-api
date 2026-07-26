<?php

declare(strict_types=1);

namespace App\Services\Currency;

use App\Services\Currency\Contracts\CurrencyExchangeServiceInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CurrencyExchangeService implements CurrencyExchangeServiceInterface
{
    private const CACHE_TTL_HOURS = 6;

    public function convert(float $amount, string $to, string $from = 'USD'): ?float
    {
        if (strtoupper($to) === strtoupper($from)) {
            return $amount;
        }

        $rate = $this->getExchangeRate($from, $to);

        return $rate ? round($amount * $rate, 2) : null;
    }

    public function getExchangeRate(string $from, string $to): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return 1.0;
        }

        $cacheKey = "exchange_rate_{$from}_{$to}";

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($from, $to) {
            $baseUrl = config('services.exchange_rate.base_url');
            $apiKey = config('services.exchange_rate.key');

            if (!$baseUrl || !$apiKey) {
                return null;
            }

            $url = "{$baseUrl}/{$apiKey}/pair/{$from}/{$to}";

            try {
                $response = Http::timeout(5)->get($url);

                if ($response->successful() && isset($response['conversion_rate'])) {
                    return (float) $response['conversion_rate'];
                }

                return null;
            } catch (\Exception $e) {
                Log::warning('Exchange rate API call failed', [
                    'from' => $from,
                    'to' => $to,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }
}
