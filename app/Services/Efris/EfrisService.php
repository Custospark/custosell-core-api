<?php

declare(strict_types=1);

namespace App\Services\Efris;

use App\Jobs\FiscalizeInvoiceJob;
use App\Jobs\FiscalizeSaleJob;
use App\Models\Invoice;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;
use Throwable;

class EfrisService implements EfrisServiceInterface
{
    public function __construct(
        private readonly EfrisClient $client,
    ) {}

    public function isEnabled(): bool
    {
        if (!filter_var(config('efris.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }
        if (strtoupper((string) config('efris.country', 'UG')) !== 'UG') {
            return false;
        }
        if ((string) config('efris.mode', 'api') !== 'api') {
            return false;
        }

        return true;
    }

    public function publicStatus(): array
    {
        $enabled = $this->isEnabled();
        $configured = $this->client->isConfigured();

        return [
            'enabled' => $enabled,
            'configured' => $configured,
            'country' => strtoupper((string) config('efris.country', 'UG')),
            'mode' => (string) config('efris.mode', 'api'),
            'environment' => (string) config('efris.environment', 'sandbox'),
            'offline_mode' => (string) config('efris.offline', 'sync_later'),
            'scope' => [
                'pos_sales' => (bool) config('efris.scope.pos_sales', true),
                'sales_invoices' => (bool) config('efris.scope.sales_invoices', true),
            ],
            'misconfigured' => $enabled && !$configured,
        ];
    }

    public function fiscalizeSale(Sale $sale, bool $forceSync = false): Sale
    {
        if (!$this->isEnabled() || !config('efris.scope.pos_sales', true)) {
            return $sale;
        }

        if (($sale->fiscal_status ?? 'none') === 'fiscalized') {
            return $sale;
        }

        // sync_later: never block checkout. Attempt URA when reachable; else enqueue.
        if (!$forceSync && !$this->isNetworkUsable()) {
            return $this->markPendingAndQueueSale($sale);
        }

        try {
            if (!$this->client->isConfigured()) {
                return $this->markFailed($sale, 'EFRIS enabled but credentials are incomplete. See docs/compliance/efris-setup.md');
            }

            $payload = $this->buildSalePayload($sale);
            $sale->fiscal_payload = $payload;
            $sale->fiscal_status = 'pending';
            $sale->save();

            $result = $this->client->submitInvoice($payload);
            $sale->fiscal_status = 'fiscalized';
            $sale->fiscal_fdn = $result['fdn'];
            $sale->fiscal_qr = $result['qr'];
            $sale->fiscal_verification_code = $result['verification_code'];
            $sale->fiscal_response = $result['raw'];
            $sale->fiscalized_at = now();
            $sale->fiscal_last_error = null;
            $sale->save();

            return $sale->fresh();
        } catch (Throwable $e) {
            Log::warning('EFRIS sale fiscalization failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
            $sale->fiscal_status = 'failed';
            $sale->fiscal_last_error = $e->getMessage();
            $sale->save();
            FiscalizeSaleJob::dispatch($sale->id)->delay(now()->addMinutes(2));

            return $sale->fresh();
        }
    }

    public function fiscalizeInvoice(Invoice $invoice, bool $forceSync = false): Invoice
    {
        if (!$this->isEnabled() || !config('efris.scope.sales_invoices', true)) {
            return $invoice;
        }

        if (($invoice->fiscal_status ?? 'none') === 'fiscalized') {
            return $invoice;
        }

        $online = $this->isNetworkUsable();
        if (!$forceSync && !$online) {
            return $this->markPendingAndQueueInvoice($invoice);
        }

        try {
            if (!$this->client->isConfigured()) {
                return $this->markFailedInvoice($invoice, 'EFRIS enabled but credentials are incomplete. See docs/compliance/efris-setup.md');
            }

            $payload = $this->buildInvoicePayload($invoice);
            $invoice->fiscal_payload = $payload;
            $invoice->fiscal_status = 'pending';
            $invoice->save();

            $result = $this->client->submitInvoice($payload);
            $invoice->fiscal_status = 'fiscalized';
            $invoice->fiscal_fdn = $result['fdn'];
            $invoice->fiscal_qr = $result['qr'];
            $invoice->fiscal_verification_code = $result['verification_code'];
            $invoice->fiscal_response = $result['raw'];
            $invoice->fiscalized_at = now();
            $invoice->fiscal_last_error = null;
            $invoice->save();

            return $invoice->fresh();
        } catch (Throwable $e) {
            Log::warning('EFRIS invoice fiscalization failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
            $invoice->fiscal_status = 'failed';
            $invoice->fiscal_last_error = $e->getMessage();
            $invoice->save();
            FiscalizeInvoiceJob::dispatch($invoice->id)->delay(now()->addMinutes(2));

            return $invoice->fresh();
        }
    }

    private function markPendingAndQueueSale(Sale $sale): Sale
    {
        $sale->fiscal_status = 'pending';
        $sale->fiscal_last_error = null;
        $sale->save();
        FiscalizeSaleJob::dispatch($sale->id);

        return $sale->fresh();
    }

    private function markPendingAndQueueInvoice(Invoice $invoice): Invoice
    {
        $invoice->fiscal_status = 'pending';
        $invoice->fiscal_last_error = null;
        $invoice->save();
        FiscalizeInvoiceJob::dispatch($invoice->id);

        return $invoice->fresh();
    }

    private function markFailed(Sale $sale, string $message): Sale
    {
        $sale->fiscal_status = 'failed';
        $sale->fiscal_last_error = $message;
        $sale->save();

        return $sale->fresh();
    }

    private function markFailedInvoice(Invoice $invoice, string $message): Invoice
    {
        $invoice->fiscal_status = 'failed';
        $invoice->fiscal_last_error = $message;
        $invoice->save();

        return $invoice->fresh();
    }

    private function isNetworkUsable(): bool
    {
        // Laravel app "online" for outbound URA — use a quick DNS/connectivity heuristic.
        // Controllers still complete sales when this is false (sync_later).
        try {
            $host = parse_url((string) config('efris.base_url'), PHP_URL_HOST);
            if (!$host) {
                return false;
            }
            $errno = 0;
            $errstr = '';
            $fp = @fsockopen('ssl://'.$host, 443, $errno, $errstr, 2);
            if ($fp) {
                fclose($fp);

                return true;
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function buildSalePayload(Sale $sale): array
    {
        $sale->loadMissing(['saleItems', 'customer', 'business']);
        $items = [];
        foreach ($sale->saleItems ?? [] as $i => $line) {
            $items[] = [
                'itemSequence' => $i + 1,
                'itemName' => $line->product_name,
                'qty' => (float) $line->quantity,
                'unitPrice' => (float) $line->unit_price,
                'total' => (float) $line->subtotal,
                'taxAmount' => (float) ($line->tax_amount ?? 0),
                'discountAmount' => (float) ($line->discount_amount ?? 0),
            ];
        }

        return [
            'sellerTin' => config('efris.tin'),
            'deviceNo' => config('efris.device_no'),
            'branchId' => config('efris.branch_id'),
            'documentType' => 'RECEIPT',
            'reference' => $sale->receipt_number,
            'issuedAt' => optional($sale->sale_date)?->toIso8601String() ?? now()->toIso8601String(),
            'buyer' => [
                'name' => $sale->customer?->name,
                'tin' => null,
                'phone' => $sale->customer?->phone,
            ],
            'totals' => [
                'subtotal' => (float) $sale->subtotal,
                'taxTotal' => (float) $sale->tax_total,
                'discount' => (float) $sale->discount_amount,
                'total' => (float) $sale->total_amount,
            ],
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function buildInvoicePayload(Invoice $invoice): array
    {
        $invoice->loadMissing(['items', 'customer', 'business']);
        $items = [];
        foreach ($invoice->items ?? [] as $i => $line) {
            $items[] = [
                'itemSequence' => $i + 1,
                'itemName' => $line->description ?? $line->product?->name ?? 'Item',
                'qty' => (float) $line->quantity,
                'unitPrice' => (float) $line->unit_price,
                'total' => (float) $line->subtotal,
                'taxAmount' => 0,
                'discountAmount' => 0,
            ];
        }

        return [
            'sellerTin' => config('efris.tin'),
            'deviceNo' => config('efris.device_no'),
            'branchId' => config('efris.branch_id'),
            'documentType' => 'INVOICE',
            'reference' => $invoice->invoice_number,
            'issuedAt' => optional($invoice->issue_date)?->toIso8601String() ?? now()->toIso8601String(),
            'buyer' => [
                'name' => $invoice->customer?->name,
                'tin' => null,
                'phone' => $invoice->customer?->phone,
            ],
            'totals' => [
                'subtotal' => (float) $invoice->subtotal,
                'taxTotal' => (float) $invoice->tax_total,
                'discount' => 0,
                'total' => (float) $invoice->total_amount,
            ],
            'items' => $items,
        ];
    }
}
