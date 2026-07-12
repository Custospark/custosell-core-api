<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Efris\EfrisServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class FiscalizeInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 120, 300, 600, 1800];

    public function __construct(
        public readonly int $invoiceId,
    ) {}

    public function handle(EfrisServiceInterface $efris): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);
        if (!$invoice) {
            return;
        }

        if (($invoice->fiscal_status ?? 'none') === 'fiscalized') {
            return;
        }

        if (!$efris->isEnabled()) {
            return;
        }

        $efris->fiscalizeInvoice($invoice, forceSync: true);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('FiscalizeInvoiceJob exhausted retries', [
            'invoice_id' => $this->invoiceId,
            'error' => $exception?->getMessage(),
        ]);

        Invoice::query()->whereKey($this->invoiceId)->update([
            'fiscal_status' => 'failed',
            'fiscal_last_error' => $exception?->getMessage() ?? 'Fiscalization retries exhausted',
        ]);
    }
}
