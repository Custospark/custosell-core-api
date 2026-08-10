<?php

namespace App\Services;

use App\Models\Location;
use App\Models\LocationProduct;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * Inventory & Supply Chain → Overview.
 *
 * Valuation uses the current product cost_price (Σ qty × cost), matching the
 * accounting reconciliation, with retail / wholesale reference pricing computed
 * alongside so the UI can show projected profit versus the two price tiers.
 *
 * The value-over-time trend is reconstructed from the stock ledger: for each
 * month end, the on-hand quantity per product is the last stock_after recorded
 * at or before that month end (summed across the location scope). Products with
 * no movement history within the window are not part of the trend series.
 */
class InventoryOverviewService
{
    private const TREND_MONTHS = 12;

    private const DEAD_STOCK_DAYS = 90;

    /**
     * @return array<string, mixed>
     */
    public function overview(int $businessId, ?int $locationId = null): array
    {
        $location = null;
        if ($locationId !== null) {
            $location = Location::forBusiness($businessId)->find($locationId);
            if (!$location) {
                abort(404, 'Location not found.');
            }
        }

        $tracked = Product::with('category:id,name')
            ->where('business_id', $businessId)
            ->get([
                'id', 'category_id', 'name', 'sku', 'type', 'unit_price',
                'wholesale_price', 'cost_price', 'stock_quantity', 'low_stock_threshold',
            ])
            ->filter(fn (Product $product): bool => $product->tracksStock());

        $branchStock = $this->loadBranchStock($businessId, $tracked->pluck('id')->all(), $locationId);

        $rows = $tracked
            ->map(fn (Product $product): array => $this->row($product, $branchStock))
            ->values();

        $lastActivity = $this->lastActivityMap($businessId, $locationId, $tracked->pluck('id')->all());
        $prices = $rows->keyBy('product_id')->map(fn (array $row): float => (float) $row['cost_price']);

        return [
            'as_of' => now()->toDateString(),
            'location_id' => $location?->id,
            'location_name' => $location?->name ?: 'All branches',
            'summary' => $this->summary($rows, $lastActivity),
            'by_category' => $this->byCategory($rows),
            'by_branch' => $this->byBranch($businessId, $rows, $locationId),
            'top_margin' => $this->marginRanking($rows, true),
            'low_margin' => $this->marginRanking($rows, false),
            'top_profit' => $this->profitRanking($rows),
            'dead_stock' => $this->deadStock($rows, $lastActivity),
            'low_stock' => $this->statusList($rows, 'low'),
            'out_of_stock' => $this->statusList($rows, 'out'),
            'trend' => $this->trend($businessId, $locationId, $prices),
        ];
    }

    /**
     * Per-branch quantity map. When scoped to a location we answer from
     * location_product so the branch view is truthful; otherwise fall back to
     * the aggregate mirror on products.stock_quantity.
     *
     * @param  array<int>  $productIds
     * @return \Illuminate\Support\Collection<string, int>
     */
    private function loadBranchStock(int $businessId, array $productIds, ?int $locationId): Collection
    {
        $rows = LocationProduct::where('business_id', $businessId)
            ->whereIn('product_id', $productIds ?: [0])
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->get(['product_id', 'location_id', 'stock_quantity']);

        if ($locationId === null) {
            return collect();
        }

        return $rows->mapWithKeys(fn (LocationProduct $lp): array => [
            (string) $lp->product_id => (int) $lp->stock_quantity,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Product $product, Collection $branchStock): array
    {
        $qty = (int) ($branchStock->get((string) $product->id) ?? $product->stock_quantity ?? 0);
        $cost = (float) ($product->cost_price ?? 0);
        $retail = (float) ($product->unit_price ?? 0);
        $wholesale = (float) ($product->wholesale_price ?? $retail);

        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'category_id' => $product->category_id,
            'category_name' => $product->category?->name ?? 'Uncategorized',
            'stock_quantity' => $qty,
            'low_stock_threshold' => (int) ($product->low_stock_threshold ?? 0),
            'cost_price' => $cost,
            'unit_price' => $retail,
            'wholesale_price' => $wholesale,
            'value_cost' => round($qty * $cost, 2),
            'value_retail' => round($qty * $retail, 2),
            'value_wholesale' => round($qty * $wholesale, 2),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @param  \Illuminate\Support\Collection<string, \Carbon\Carbon|null>  $lastActivity
     * @return array<string, mixed>
     */
    private function summary(Collection $rows, Collection $lastActivity): array
    {
        $valuation = $rows->filter(fn (array $r): bool => $r['stock_quantity'] > 0 && (float) $r['cost_price'] > 0);

        $valueCost = (float) $valuation->sum('value_cost');
        $valueRetail = (float) $valuation->sum('value_retail');
        $valueWholesale = (float) $valuation->sum('value_wholesale');

        $profitRetail = $valueRetail - $valueCost;
        $profitWholesale = $valueWholesale - $valueCost;

        return [
            'product_count' => $rows->count(),
            'stocked_product_count' => $rows->filter(fn (array $r): bool => $r['stock_quantity'] > 0)->count(),
            'stock_quantity' => (int) $rows->sum('stock_quantity'),
            'value_cost' => round($valueCost, 2),
            'value_retail' => round($valueRetail, 2),
            'value_wholesale' => round($valueWholesale, 2),
            'profit_retail' => round($profitRetail, 2),
            'profit_retail_pct' => $valueCost > 0 ? round($profitRetail / $valueCost * 100, 2) : null,
            'profit_wholesale' => round($profitWholesale, 2),
            'profit_wholesale_pct' => $valueCost > 0 ? round($profitWholesale / $valueCost * 100, 2) : null,
            'low_stock_count' => $rows->filter(fn (array $r): bool => $this->isLow($r))->count(),
            'out_of_stock_count' => $rows->filter(fn (array $r): bool => $r['stock_quantity'] === 0)->count(),
            'dead_stock_count' => count($this->deadStock($rows, $lastActivity)),
            'zero_cost_sku_count' => $rows->filter(fn (array $r): bool => $r['stock_quantity'] > 0 && (float) $r['cost_price'] <= 0)->count(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function byCategory(Collection $rows): array
    {
        return $rows
            ->groupBy('category_id')
            ->map(function (Collection $group): array {
                $qty = (int) $group->sum('stock_quantity');

                return [
                    'category_id' => $group->first()['category_id'],
                    'category_name' => $group->first()['category_name'],
                    'product_count' => $group->filter(fn (array $r): bool => $r['stock_quantity'] > 0)->count(),
                    'stock_quantity' => $qty,
                    'value_cost' => round((float) $group->sum('value_cost'), 2),
                    'value_retail' => round((float) $group->sum('value_retail'), 2),
                    'value_wholesale' => round((float) $group->sum('value_wholesale'), 2),
                ];
            })
            ->sortByDesc('value_cost')
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function byBranch(int $businessId, Collection $rows, ?int $locationId): array
    {
        $locations = Location::forBusiness($businessId)
            ->where('is_active', true)
            ->get(['id', 'name']);

        $grouped = LocationProduct::where('business_id', $businessId)
            ->whereIn('product_id', $rows->pluck('product_id')->all() ?: [0])
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->get(['location_id', 'product_id', 'stock_quantity'])
            ->groupBy('location_id');

        $overallCost = (float) $rows->sum('value_cost');
        $prices = $rows->keyBy('product_id');

        $result = $locations
            ->filter(fn (Location $location): bool => $grouped->has($location->id))
            ->map(function (Location $location) use ($grouped, $prices, $overallCost): array {
                $items = $grouped->get($location->id);
                $value = 0.0;
                $retail = 0.0;
                $wholesale = 0.0;
                $qty = 0;
                $count = 0;

                foreach ($items as $lp) {
                    $qty += (int) $lp->stock_quantity;
                    $row = $prices->get((string) $lp->product_id);
                    if (!$row) {
                        continue;
                    }
                    $value += (float) $row['cost_price'] * (int) $lp->stock_quantity;
                    $retail += (float) $row['unit_price'] * (int) $lp->stock_quantity;
                    $wholesale += (float) $row['wholesale_price'] * (int) $lp->stock_quantity;
                    if ((int) $lp->stock_quantity > 0) {
                        $count++;
                    }
                }

                $cost = round($value, 2);

                return [
                    'location_id' => $location->id,
                    'location_name' => $location->name,
                    'product_count' => $count,
                    'stock_quantity' => $qty,
                    'value_cost' => $cost,
                    'value_retail' => round($retail, 2),
                    'value_wholesale' => round($wholesale, 2),
                    'share_pct' => $overallCost > 0 ? round($cost / $overallCost * 100, 2) : null,
                ];
            })
            ->sortByDesc('value_cost')
            ->values()
            ->all();

        return $result;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function marginRanking(Collection $rows, bool $top): array
    {
        $tradeable = $rows
            ->filter(fn (array $r): bool => $r['stock_quantity'] > 0 && (float) $r['cost_price'] > 0 && (float) $r['unit_price'] > 0)
            ->map(function (array $r): array {
                $cost = (float) $r['cost_price'];
                $retail = (float) $r['unit_price'];
                $wholesale = (float) $r['wholesale_price'];

                return [
                    'product_id' => $r['product_id'],
                    'name' => $r['name'],
                    'sku' => $r['sku'],
                    'category_name' => $r['category_name'],
                    'stock_quantity' => $r['stock_quantity'],
                    'value_cost' => $r['value_cost'],
                    'value_retail' => $r['value_retail'],
                    'value_wholesale' => $r['value_wholesale'],
                    'margin_retail_pct' => round(($retail - $cost) / $cost * 100, 2),
                    'margin_wholesale_pct' => round(($wholesale - $cost) / $cost * 100, 2),
                ];
            })
            ->values();

        $sorted = $tradeable->sortBy('margin_retail_pct');

        return ($top ? $sorted->reverse() : $sorted)->take(10)->values()->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function profitRanking(Collection $rows): array
    {
        return $rows
            ->filter(fn (array $r): bool => $r['stock_quantity'] > 0 && (float) $r['cost_price'] > 0)
            ->map(fn (array $r): array => [
                'product_id' => $r['product_id'],
                'name' => $r['name'],
                'sku' => $r['sku'],
                'stock_quantity' => $r['stock_quantity'],
                'value_cost' => $r['value_cost'],
                'value_retail' => $r['value_retail'],
                'value_wholesale' => $r['value_wholesale'],
                'profit_retail' => round((float) $r['value_retail'] - (float) $r['value_cost'], 2),
            ])
            ->sortByDesc('profit_retail')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Stock on hand (cost > 0) with no ledger activity within DEAD_STOCK_DAYS.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @param  \Illuminate\Support\Collection<string, \Carbon\Carbon|null>  $lastActivity
     * @return array<int, array<string, mixed>>
     */
    private function deadStock(Collection $rows, Collection $lastActivity): array
    {
        $cutoff = now()->subDays(self::DEAD_STOCK_DAYS);

        return $rows
            ->filter(fn (array $r): bool => $r['stock_quantity'] > 0 && (float) $r['cost_price'] > 0)
            ->filter(function (array $r) use ($lastActivity, $cutoff): bool {
                $at = $lastActivity->get((string) $r['product_id']);

                return $at === null || $at->lt($cutoff);
            })
            ->map(function (array $r) use ($lastActivity): array {
                $at = $lastActivity->get((string) $r['product_id']);

                return [
                    'product_id' => $r['product_id'],
                    'name' => $r['name'],
                    'sku' => $r['sku'],
                    'stock_quantity' => $r['stock_quantity'],
                    'value_cost' => $r['value_cost'],
                    'cost_price' => $r['cost_price'],
                    'last_activity' => $at?->toDateString(),
                    'dead_days' => $at === null ? null : max(0, (int) $at->diffInDays(now())),
                ];
            })
            ->sortByDesc('value_cost')
            ->take(10)
            ->values()
            ->all();
    }

    private function isLow(array $r): bool
    {
        return $r['stock_quantity'] > 0
            && (int) $r['low_stock_threshold'] > 0
            && $r['stock_quantity'] <= (int) $r['low_stock_threshold'];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function statusList(Collection $rows, string $type): array
    {
        return $rows
            ->filter(fn (array $r): bool => $type === 'low' ? $this->isLow($r) : $r['stock_quantity'] === 0)
            ->map(fn (array $r): array => [
                'product_id' => $r['product_id'],
                'name' => $r['name'],
                'sku' => $r['sku'],
                'category_name' => $r['category_name'],
                'stock_quantity' => $r['stock_quantity'],
                'low_stock_threshold' => $r['low_stock_threshold'],
                'value_cost' => $r['value_cost'],
            ])
            ->sortBy('stock_quantity')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Latest ledger activity per product, scoped by location when one is given.
     *
     * @param  array<int>  $productIds
     * @return \Illuminate\Support\Collection<string, \Carbon\Carbon|null>
     */
    private function lastActivityMap(int $businessId, ?int $locationId, array $productIds): Collection
    {
        return StockMovement::where('business_id', $businessId)
            ->whereIn('product_id', $productIds ?: [0])
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->selectRaw('product_id, MAX(created_at) as last_at')
            ->groupBy('product_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (string) $row->product_id => $row->last_at ? \Carbon\Carbon::parse($row->last_at) : null,
            ]);
    }

    /**
     * Month-end value reconstruction from the stock ledger.
     *
     * @param  \Illuminate\Support\Collection<string, float>  $prices  product_id → cost
     * @return array<int, array<string, mixed>>
     */
    private function trend(int $businessId, ?int $locationId, Collection $prices): array
    {
        $start = now()->subMonths(self::TREND_MONTHS - 1)->startOfMonth();

        $movements = StockMovement::where('business_id', $businessId)
            ->where('created_at', '>=', $start)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->get(['product_id', 'location_id', 'created_at', 'stock_after'])
            ->groupBy('product_id');

        $labels = [];
        $ends = [];
        $buckets = [];

        for ($i = self::TREND_MONTHS - 1; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $key = $month->format('Y-m');
            $labels[$key] = $month->format('M Y');
            $ends[$key] = $month->copy()->endOfMonth();
            $buckets[$key] = ['month' => $key, 'label' => $labels[$key], 'value_cost' => 0.0, 'stock_quantity' => 0];
        }

        foreach ($movements as $productId => $productMovements) {
            $cost = $prices->get((string) $productId, 0.0);
            $timelines = $productMovements->groupBy('location_id')
                ->map(fn (Collection $rows): Collection => $rows->sortBy('created_at')->values());

            foreach ($buckets as $key => $bucket) {
                $qty = 0;
                foreach ($timelines as $rows) {
                    $snapshot = $rows->filter(fn ($m) => $m->created_at->lte($ends[$key]))->last();
                    if ($snapshot !== null) {
                        $qty += (int) $snapshot->stock_after;
                    }
                }
                $buckets[$key]['value_cost'] += round($qty * $cost, 2);
                $buckets[$key]['stock_quantity'] += $qty;
            }
        }

        return array_values($buckets);
    }
}