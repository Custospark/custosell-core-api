<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Facades\Log;

class InventoryCogsService
{
    public function __construct(
        protected LedgerService $ledgerService,
    ) {}

    /**
     * COGS for a sale using net fulfilled quantity (quantity minus refunds).
     */
    public function calculateSaleCogs(Sale $sale): float
    {
        $sale->loadMissing('saleItems.product');
        $total = 0.0;

        foreach ($sale->saleItems as $item) {
            $total += $this->calculateLineCogs($item);
        }

        return round($total, 2);
    }

    /**
     * COGS restored on a refund batch (cost × refunded quantity).
     *
     * @param  array<int, array{saleItem: SaleItem, refundQty: int}>  $refundBatch
     */
    public function calculateRefundCogs(array $refundBatch): float
    {
        $total = 0.0;

        foreach ($refundBatch as $row) {
            if (!isset($row['saleItem']) || !$row['saleItem'] instanceof SaleItem) {
                continue;
            }
            /** @var SaleItem $saleItem */
            $saleItem = $row['saleItem'];
            $refundQty = (int) ($row['refundQty'] ?? 0);
            if ($refundQty <= 0) {
                continue;
            }
            $unitCost = $this->resolveUnitCost($saleItem);
            if ($unitCost > 0) {
                $total += $unitCost * $refundQty;
            }
        }

        return round($total, 2);
    }

    /**
     * Invoices are billing documents — COGS is recognised on stock-moving sales only.
     */
    public function calculateInvoiceCogs(Invoice $invoice): float
    {
        return 0.0;
    }

    public function calculateLineCogs(SaleItem $item): float
    {
        $netQty = max(0, (int) $item->quantity - (int) $item->refunded_quantity);
        if ($netQty <= 0 || !$item->product_id) {
            return 0.0;
        }

        $unitCost = $this->resolveUnitCost($item);
        if ($unitCost <= 0) {
            return 0.0;
        }

        return round($unitCost * $netQty, 2);
    }

    public function resolveUnitCost(SaleItem $item): float
    {
        if ((float) ($item->unit_cost ?? 0) > 0) {
            return (float) $item->unit_cost;
        }

        $product = $item->relationLoaded('product') ? $item->product : Product::find($item->product_id);
        return $product ? (float) $product->cost_price : 0.0;
    }

    /**
     * Book value of on-hand stock (operational), not GL.
     */
    /**
     * @deprecated Use InventoryLedgerService::calculateSanitizedStockBookValue()
     */
    public function calculateStockBookValue(int $businessId): float
    {
        return app(InventoryLedgerService::class)->calculateSanitizedStockBookValue($businessId);
    }

  /**
     * Current inventory GL balance (account 1104).
     */
    public function getInventoryLedgerBalance(int $businessId, ?int $periodId = null): float
    {
        $account = ChartOfAccount::query()
            ->where('business_id', $businessId)
            ->where('code', config('accounting.default_account_codes.inventory', '1104'))
            ->first();

        if (!$account) {
            return 0.0;
        }

        return round($this->ledgerService->calculateAccountBalance($account->id, $businessId, $periodId), 2);
    }

    /**
     * Cap inventory credit so the GL inventory account cannot go negative.
     *
     * @return array{cogs: float, inventory_credit: float, uncapped: float}
     */
    public function capCogsToAvailableInventory(int $businessId, float $requestedCogs, ?int $periodId = null): array
    {
        $requestedCogs = round(max(0, $requestedCogs), 2);
        $available = max(0, $this->getInventoryLedgerBalance($businessId, $periodId));
        $inventoryCredit = round(min($requestedCogs, $available), 2);

        if ($inventoryCredit < $requestedCogs) {
            Log::warning('COGS inventory credit capped to available GL balance', [
                'business_id' => $businessId,
                'requested_cogs' => $requestedCogs,
                'available_inventory' => $available,
                'posted_cogs' => $inventoryCredit,
            ]);
        }

        return [
            'cogs' => $inventoryCredit,
            'inventory_credit' => $inventoryCredit,
            'uncapped' => $requestedCogs,
        ];
    }
}
