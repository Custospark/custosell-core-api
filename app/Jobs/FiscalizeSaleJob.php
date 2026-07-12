<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Sale;
use App\Services\Efris\EfrisServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class FiscalizeSaleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 120, 300, 600, 1800];

    public function __construct(
        public readonly int $saleId,
    ) {}

    public function handle(EfrisServiceInterface $efris): void
    {
        $sale = Sale::query()->find($this->saleId);
        if (!$sale) {
            return;
        }

        if (($sale->fiscal_status ?? 'none') === 'fiscalized') {
            return;
        }

        if (!$efris->isEnabled()) {
            return;
        }

        $efris->fiscalizeSale($sale, forceSync: true);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('FiscalizeSaleJob exhausted retries', [
            'sale_id' => $this->saleId,
            'error' => $exception?->getMessage(),
        ]);

        Sale::query()->whereKey($this->saleId)->update([
            'fiscal_status' => 'failed',
            'fiscal_last_error' => $exception?->getMessage() ?? 'Fiscalization retries exhausted',
        ]);
    }
}
