<?php

declare(strict_types=1);

namespace App\Services\Efris;

use App\Models\Invoice;
use App\Models\Sale;

interface EfrisServiceInterface
{
    /** Whether fiscalization is active for this deployment. */
    public function isEnabled(): bool;

    /** Safe public status for Settings UI (no credentials). */
    public function publicStatus(): array;

    /**
     * Attempt to fiscalize a POS sale (or enqueue when offline / soft-fail).
     * Never throws out of SaleService — sale must succeed regardless.
     */
    public function fiscalizeSale(Sale $sale, bool $forceSync = false): Sale;

    /**
     * Attempt to fiscalize a sent sales invoice (or enqueue when offline / soft-fail).
     */
    public function fiscalizeInvoice(Invoice $invoice, bool $forceSync = false): Invoice;
}
