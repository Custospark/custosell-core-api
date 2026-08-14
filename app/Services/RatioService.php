<?php

namespace App\Services;

use App\Models\AccountType;
use App\Models\ChartOfAccount;
use App\Models\AccountingPeriod;
use App\Support\ReportPeriodContext;

class RatioService
{
    public function __construct(
        protected FinancialStatementService $financialStatementService,
        protected LedgerService $ledgerService,
        protected RatioRecommendationText $recommendationText,
    ) {}

    public function calculateAll(int $businessId, int $periodId): array
    {
        $period = AccountingPeriod::findOrFail($periodId);
        $ctx = new ReportPeriodContext(
            periodIds: [$periodId],
            snapshotPeriodId: $periodId,
            priorSnapshotPeriodId: AccountingPeriod::query()
                ->where('business_id', $businessId)
                ->where('end_date', '<', $period->start_date)
                ->orderByDesc('end_date')
                ->value('id'),
            dateFrom: $period->start_date->toDateString(),
            dateTo: $period->end_date->toDateString(),
            label: $period->name,
            isRange: false,
        );

        return $this->calculateAllForContext($businessId, $ctx);
    }

    public function calculateAllForContext(int $businessId, ReportPeriodContext $ctx): array
    {
        $is = $this->financialStatementService->incomeStatementForPeriods($businessId, $ctx->periodIds);
        $bs = $this->financialStatementService->balanceSheetForContext($businessId, $ctx);

        $liquidity = $this->getLiquidityRatios($businessId, $ctx->snapshotPeriodId);
        $profitability = $this->getProfitabilityRatios($businessId, $ctx->snapshotPeriodId, $is, $bs);
        $solvency = $this->getSolvencyRatios($businessId, $ctx->snapshotPeriodId, $is, $bs);
        $efficiency = $this->getEfficiencyRatios($businessId, $ctx->snapshotPeriodId, $is, $bs);

        $grouped = compact('liquidity', 'profitability', 'solvency', 'efficiency');

        return array_merge($grouped, [
            'recommendations' => $this->getRecommendationsFromRatios($grouped),
            'period_id' => $ctx->snapshotPeriodId,
            'period' => [
                'id' => $ctx->snapshotPeriodId,
                'name' => $ctx->label,
                'start_date' => $ctx->dateFrom,
                'end_date' => $ctx->dateTo,
                'period_ids' => $ctx->periodIds,
                'is_range' => $ctx->isRange,
            ],
        ]);
    }

    public function getTrends(int $businessId, string $interval = 'monthly', int $count = 12): array
    {
        $periods = AccountingPeriod::where('business_id', $businessId)
            ->where('is_closed', true)
            ->orderBy('end_date', 'desc')
            ->take($count)
            ->get()
            ->reverse()
            ->values();

        if ($periods->isEmpty()) {
            $periods = AccountingPeriod::where('business_id', $businessId)
                ->orderBy('end_date', 'desc')
                ->take($count)
                ->get()
                ->reverse()
                ->values();
        }

        $trends = [];
        foreach ($periods as $period) {
            $trends[] = [
                'period_id' => $period->id,
                'period_name' => $period->name,
                'start_date' => $period->start_date->toDateString(),
                'end_date' => $period->end_date->toDateString(),
                'ratios' => $this->calculateAll($businessId, $period->id),
            ];
        }

        return $trends;
    }

    public function getLiquidityRatios(int $businessId, int $periodId): array
    {
        $currentAssets = $this->getSignedAccountBalanceByCodes($businessId, $periodId, 'Asset', ['1101', '1102', '1103', '1104', '1105']);
        $currentLiabilities = $this->getSignedAccountBalanceByCodes($businessId, $periodId, 'Liability', ['2101', '2102', '2103', '2104', '2110', '2111', '2112']);
        $inventory = $this->getSignedAccountBalanceByCodes($businessId, $periodId, 'Asset', ['1104']);
        $cashAndEquivalents = $this->getSignedAccountBalanceByCodes($businessId, $periodId, 'Asset', ['1101', '1102']);

        $currentRatio = $this->safeDivide($currentAssets, $currentLiabilities);
        $quickAssets = $currentAssets - $inventory;
        $quickRatio = $this->safeDivide($quickAssets, $currentLiabilities);
        $cashRatio = $this->safeDivide($cashAndEquivalents, $currentLiabilities);

        return [
            'current_ratio' => $currentRatio !== null ? round($currentRatio, 2) : null,
            'quick_ratio' => $quickRatio !== null ? round($quickRatio, 2) : null,
            'cash_ratio' => $cashRatio !== null ? round($cashRatio, 2) : null,
        ];
    }

    public function getProfitabilityRatios(int $businessId, int $periodId, ?array $is = null, ?array $bs = null): array
    {
        $is ??= $this->financialStatementService->incomeStatement($businessId, $periodId);
        $bs ??= $this->financialStatementService->balanceSheet($businessId, $periodId);

        $revenue = (float) ($is['total_revenue'] ?? 0);
        $netIncome = (float) ($is['net_income'] ?? 0);
        $grossProfit = (float) ($is['gross_profit'] ?? 0);
        $totalAssets = (float) ($bs['total_assets'] ?? 0);
        $totalEquity = (float) ($bs['total_equity'] ?? 0);

        $roa = $this->safeDivide($netIncome, $totalAssets);
        $roe = $this->safeDivide($netIncome, $totalEquity);

        return [
            'gross_profit_margin' => $revenue != 0 ? round(($grossProfit / $revenue) * 100, 2) : null,
            'net_profit_margin' => $revenue != 0 ? round(($netIncome / $revenue) * 100, 2) : null,
            'return_on_assets' => $roa !== null ? round($roa * 100, 2) : null,
            'return_on_equity' => $roe !== null ? round($roe * 100, 2) : null,
        ];
    }

    public function getSolvencyRatios(int $businessId, int $periodId, ?array $is = null, ?array $bs = null): array
    {
        $bs ??= $this->financialStatementService->balanceSheet($businessId, $periodId);
        $is ??= $this->financialStatementService->incomeStatement($businessId, $periodId);

        $totalLiabilities = (float) ($bs['total_liabilities'] ?? 0);
        $totalAssets = (float) ($bs['total_assets'] ?? 0);
        $totalEquity = (float) ($bs['total_equity'] ?? 0);
        $operatingIncome = (float) ($is['operating_income'] ?? 0);
        $interestExpense = (float) ($is['interest_expense'] ?? 0);

        $debtToEquity = $this->safeDivide($totalLiabilities, $totalEquity);
        $debtRatio = $this->safeDivide($totalLiabilities, $totalAssets);
        $interestCoverage = $interestExpense > 0
            ? $this->safeDivide($operatingIncome, $interestExpense)
            : null;

        return [
            'debt_to_equity' => $debtToEquity !== null ? round($debtToEquity, 2) : null,
            'debt_ratio' => $debtRatio !== null ? round($debtRatio, 2) : null,
            'interest_coverage_ratio' => $interestCoverage !== null ? round($interestCoverage, 2) : null,
        ];
    }

    public function getEfficiencyRatios(int $businessId, int $periodId, ?array $is = null, ?array $bs = null): array
    {
        $bs ??= $this->financialStatementService->balanceSheet($businessId, $periodId);
        $is ??= $this->financialStatementService->incomeStatement($businessId, $periodId);

        $revenue = (float) ($is['total_revenue'] ?? 0);
        $cogs = (float) ($is['total_cost_of_goods_sold'] ?? 0);
        $totalAssets = (float) ($bs['total_assets'] ?? 0);
        $inventory = $this->getSignedAccountBalanceByCodes($businessId, $periodId, 'Asset', ['1104']);
        $accountsReceivable = $this->getSignedAccountBalanceByCodes($businessId, $periodId, 'Asset', ['1103']);

        $assetTurnover = $this->safeDivide($revenue, $totalAssets);
        $inventoryTurnover = $inventory > 0 ? $this->safeDivide($cogs, $inventory) : null;
        $arTurnover = $accountsReceivable > 0 ? $this->safeDivide($revenue, $accountsReceivable) : null;

        return [
            'asset_turnover' => $assetTurnover !== null ? round($assetTurnover, 2) : null,
            'inventory_turnover' => $inventoryTurnover !== null ? round($inventoryTurnover, 2) : null,
            'accounts_receivable_turnover' => $arTurnover !== null ? round($arTurnover, 2) : null,
        ];
    }

    protected function safeDivide(float $numerator, float $denominator): ?float
    {
        if ($denominator == 0) {
            // No liabilities = excellent liquidity, but ratio is technically undefined
            // Return null so the frontend can display N/A with context
            return null;
        }
        if ($denominator < 0) {
            $denominator = abs($denominator);
        }
        return $numerator / $denominator;
    }

    public function getRecommendationsFromRatios(array $ratios): array
    {
        $recs = [];

        $mapping = [
            'liquidity' => [
                'current_ratio' => ['label' => 'Current Ratio', 'higher_is_better' => true, 'healthy' => 2.0, 'warning' => 1.0],
                'quick_ratio' => ['label' => 'Quick Ratio', 'higher_is_better' => true, 'healthy' => 1.0, 'warning' => 0.5],
                'cash_ratio' => ['label' => 'Cash Ratio', 'higher_is_better' => true, 'healthy' => 0.5, 'warning' => 0.3],
            ],
            'profitability' => [
                'gross_profit_margin' => ['label' => 'Gross Profit Margin', 'higher_is_better' => true, 'healthy' => 40, 'warning' => 20],
                'net_profit_margin' => ['label' => 'Net Profit Margin', 'higher_is_better' => true, 'healthy' => 15, 'warning' => 5],
                'return_on_assets' => ['label' => 'Return on Assets', 'higher_is_better' => true, 'healthy' => 10, 'warning' => 5],
                'return_on_equity' => ['label' => 'Return on Equity', 'higher_is_better' => true, 'healthy' => 15, 'warning' => 10],
            ],
            'solvency' => [
                'debt_to_equity' => ['label' => 'Debt to Equity', 'higher_is_better' => false, 'healthy' => 1.0, 'warning' => 2.0],
                'debt_ratio' => ['label' => 'Debt Ratio', 'higher_is_better' => false, 'healthy' => 0.5, 'warning' => 0.7],
                'interest_coverage_ratio' => ['label' => 'Interest Coverage Ratio', 'higher_is_better' => true, 'healthy' => 3.0, 'warning' => 1.5],
            ],
            'efficiency' => [
                'asset_turnover' => ['label' => 'Asset Turnover', 'higher_is_better' => true, 'healthy' => 1.5, 'warning' => 0.8],
                'inventory_turnover' => ['label' => 'Inventory Turnover', 'higher_is_better' => true, 'healthy' => 6.0, 'warning' => 3.0],
                'accounts_receivable_turnover' => ['label' => 'Accounts Receivable Turnover', 'higher_is_better' => true, 'healthy' => 8.0, 'warning' => 4.0],
            ],
        ];

        foreach ($mapping as $category => $ratiosInCategory) {
            foreach ($ratiosInCategory as $key => $def) {
                $value = $ratios[$category][$key] ?? null;
                if ($value === null) continue;

                $higherIsBetter = $def['higher_is_better'];
                if ($higherIsBetter) {
                    if ($value >= $def['healthy']) {
                        $status = 'healthy';
                        $priority = 'low';
                    } elseif ($value >= $def['warning']) {
                        $status = 'warning';
                        $priority = 'medium';
                    } else {
                        $status = 'danger';
                        $priority = 'high';
                    }
                } else {
                    if ($value <= $def['healthy']) {
                        $status = 'healthy';
                        $priority = 'low';
                    } elseif ($value <= $def['warning']) {
                        $status = 'warning';
                        $priority = 'medium';
                    } else {
                        $status = 'danger';
                        $priority = 'high';
                    }
                }

                $recs[] = [
                    'category' => $category,
                    'ratio_key' => $key,
                    'label' => $def['label'],
                    'status' => $status,
                    'value' => $value,
                    'message' => $this->recommendationText->message($key, $value, $status),
                    'action' => $this->recommendationText->action($key, $status),
                    'priority' => $priority,
                ];
            }
        }

        usort($recs, function ($a, $b) {
            $order = ['high' => 0, 'medium' => 1, 'low' => 2];
            return $order[$a['priority']] <=> $order[$b['priority']];
        });

        return $recs;
    }

    protected function getSignedAccountBalanceByCodes(int $businessId, int $periodId, string $typeName, array $codes): float
    {
        $type = AccountType::where('name', $typeName)->first();
        if (!$type) {
            return 0;
        }

        $section = match ($typeName) {
            'Asset' => 'asset',
            'Liability' => 'liability',
            'Equity' => 'equity',
            default => 'asset',
        };

        $accounts = ChartOfAccount::where('business_id', $businessId)
            ->where('type_id', $type->id)
            ->whereIn('code', $codes)
            ->where('is_active', true)
            ->get();

        $total = 0.0;
        foreach ($accounts as $account) {
            $raw = $this->ledgerService->calculateAccountBalance($account->id, $businessId, $periodId);
            $total += match ($section) {
                'asset' => $account->normal_balance === 'debit' ? $raw : -$raw,
                'liability', 'equity' => $account->normal_balance === 'credit' ? $raw : -$raw,
                default => $raw,
            };
        }

        return $total;
    }

    protected function getAccountBalanceByCodes(int $businessId, int $periodId, string $typeName, array $codes): float
    {
        return $this->getSignedAccountBalanceByCodes($businessId, $periodId, $typeName, $codes);
    }
}
