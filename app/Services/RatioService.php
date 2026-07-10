<?php

namespace App\Services;

use App\Models\AccountType;
use App\Models\ChartOfAccount;
use App\Models\AccountingPeriod;
use App\Support\ReportPeriodContext;
use Illuminate\Support\Facades\Log;

class RatioService
{
    public function __construct(
        protected FinancialStatementService $financialStatementService,
        protected LedgerService $ledgerService,
    ) {}

    public function calculateAll(int $businessId, int $periodId): array
    {
        $ctx = new ReportPeriodContext(
            periodIds: [$periodId],
            snapshotPeriodId: $periodId,
            priorSnapshotPeriodId: AccountingPeriod::query()
                ->where('business_id', $businessId)
                ->where('end_date', '<', AccountingPeriod::findOrFail($periodId)->start_date)
                ->orderByDesc('end_date')
                ->value('id'),
            dateFrom: AccountingPeriod::findOrFail($periodId)->start_date->toDateString(),
            dateTo: AccountingPeriod::findOrFail($periodId)->end_date->toDateString(),
            label: AccountingPeriod::findOrFail($periodId)->name,
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

                $message = $this->generateMessage($key, $value, $status);
                $action = $this->generateAction($key, $status);

                $recs[] = [
                    'category' => $category,
                    'ratio_key' => $key,
                    'label' => $def['label'],
                    'status' => $status,
                    'value' => $value,
                    'message' => $message,
                    'action' => $action,
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

    protected function generateMessage(string $key, float $value, string $status): string
    {
        $messages = [
            'current_ratio' => [
                'healthy' => sprintf(
                    'Your Current Ratio of %.2f indicates strong short-term liquidity. The business has more than enough current assets to cover immediate obligations. This healthy buffer allows you to negotiate better payment terms with suppliers and take advantage of early-payment discounts.',
                    $value
                ),
                'warning' => sprintf(
                    'Your Current Ratio of %.2f is below the healthy threshold of 2.0. While you can still meet obligations, there is limited room for error. Consider accelerating accounts receivable collection, reducing unnecessary inventory, or negotiating extended payment terms with key suppliers.',
                    $value
                ),
                'danger' => sprintf(
                    'Your Current Ratio of %.2f is critically low — current liabilities exceed current assets. This signals potential cash flow problems. Immediate actions: (1) review all payables and prioritize critical payments, (2) accelerate customer collections aggressively, (3) consider a short-term working capital facility, and (4) identify any non-essential assets that could be liquidated.',
                    $value
                ),
            ],
            'quick_ratio' => [
                'healthy' => sprintf(
                    'Your Quick Ratio of %.2f confirms strong liquidity even without relying on inventory. This is a robust position — you can meet sudden obligations without distress. Consider using this stability to negotiate supplier discounts for early payment.',
                    $value
                ),
                'warning' => sprintf(
                    'Your Quick Ratio of %.2f is below the ideal of 1.0, meaning you depend on selling inventory to meet short-term debts. Focus on converting inventory to cash faster by offering limited-time discounts on slow-moving stock.',
                    $value
                ),
                'danger' => sprintf(
                    'Your Quick Ratio of %.2f indicates serious liquidity pressure without inventory sales. This is a red flag for creditors and suppliers. Take immediate steps to improve cash conversion: tighten credit terms, follow up on overdue accounts daily, and consider invoice factoring as a short-term solution.',
                    $value
                ),
            ],
            'cash_ratio' => [
                'healthy' => sprintf(
                    'Cash reserves of %.2fx current liabilities provide a solid emergency fund. You have immediate firepower to handle unexpected expenses or seize growth opportunities without needing external financing.',
                    $value
                ),
                'warning' => sprintf(
                    'Cash on hand covers only %.2fx of current liabilities. While not critical, this leaves limited buffer for unexpected expenses. Aim to maintain cash reserves covering at least 50%% of current liabilities. Review accounts receivable aging weekly to ensure consistent cash inflow.',
                    $value
                ),
                'danger' => sprintf(
                    'Cash reserves are critically low at %.2fx of current liabilities. Any unexpected expense could cause a cash crunch. Immediate cash conservation is needed: delay non-essential purchases, negotiate payment extensions, and explore a business line of credit before it becomes an emergency.',
                    $value
                ),
            ],
            'gross_profit_margin' => [
                'healthy' => sprintf(
                    'Gross Margin of %.2f%% demonstrates strong pricing power and effective cost management. Your business captures excellent profit from every sale. Consider whether this allows room for strategic market share expansion through targeted promotions.',
                    $value
                ),
                'warning' => sprintf(
                    'Gross Margin of %.2f%% is below the 40%%%% benchmark. Review your pricing strategy against competitors — a small price increase of 3-5%%%% could significantly impact profitability if demand is inelastic. Also review supplier contracts and consider bulk purchasing or alternative suppliers.',
                    $value
                ),
                'danger' => sprintf(
                    'Gross Margin of %.2f%% is critically low — your cost of goods is consuming most of your revenue. This is a serious profitability issue requiring immediate attention. (1) Conduct a full supplier cost review, (2) evaluate whether you can pass cost increases to customers, (3) analyze product-level margins and consider discontinuing low-margin items, and (4) look for operational efficiencies in your production or procurement process.',
                    $value
                ),
            ],
            'net_profit_margin' => [
                'healthy' => sprintf(
                    'Net Margin of %.2f%% is excellent — your cost structure is well-managed across all operating expenses. This profitability gives you flexibility to invest in growth, build reserves, or reward owners. Continue monitoring expense ratios to maintain this healthy position.',
                    $value
                ),
                'warning' => sprintf(
                    'Net Margin of %.2f%% indicates that while you are profitable, there is significant room for improvement. Review your operating expenses line by line: rent, salaries, utilities, and administrative costs. A 2-3%%%% improvement in net margin would substantially increase bottom-line profits.',
                    $value
                ),
                'danger' => sprintf(
                    'Net Margin of %.2f%% is very thin — your business retains very little profit from each shilling earned. Every cost category needs scrutiny. Consider: (1) automating manual processes to reduce labour costs, (2) renegotiating recurring contracts (rent, insurance, software), (3) reviewing staffing levels against revenue, and (4) implementing cost-control measures with departmental budgets.',
                    $value
                ),
            ],
            'return_on_assets' => [
                'healthy' => sprintf(
                    'ROA of %.2f%% indicates your assets are working efficiently to generate profits. This signals strong management and operational discipline. Your asset base is well-utilized — benchmark this against industry peers to confirm competitive advantage.',
                    $value
                ),
                'warning' => sprintf(
                    'ROA of %.2f%% suggests some assets may be underperforming. Identify assets with low utilization — idle equipment, excess inventory, or underperforming investments. Selling or repurposing these assets could improve overall returns and free up capital for higher-yield opportunities.',
                    $value
                ),
                'danger' => sprintf(
                    'ROA of %.2f%% indicates assets are not generating sufficient returns. This may mean over-investment in assets relative to business volume, or operational issues limiting profitability. A comprehensive asset utilization review is needed — consider asset-light strategies like leasing instead of owning equipment.',
                    $value
                ),
            ],
            'return_on_equity' => [
                'healthy' => sprintf(
                    'ROE of %.2f%% demonstrates strong value creation for shareholders and owners. Your business is generating excellent returns on invested capital. This positions you well for attracting investment or financing for expansion.',
                    $value
                ),
                'warning' => sprintf(
                    'ROE of %.2f%% is moderate. While the business is generating positive returns, they may not exceed the cost of equity. Focus on improving net profitability and optimizing the capital structure — consider whether debt financing could amplify returns without excessive risk.',
                    $value
                ),
                'danger' => sprintf(
                    'ROE of %.2f%% is below what investors typically expect. Low returns on equity may indicate that the business is not effectively using shareholder capital. Consider: (1) a strategic review of unprofitable product lines, (2) evaluating whether excess cash should be distributed to owners, and (3) developing a clear growth strategy to improve future returns.',
                    $value
                ),
            ],
            'debt_to_equity' => [
                'healthy' => sprintf(
                    'Debt-to-Equity of %.2f reflects a conservative, low-risk capital structure. The business relies primarily on owner financing rather than debt. This financial flexibility means you can access debt financing when growth opportunities arise, as lenders will view your low leverage favorably.',
                    $value
                ),
                'warning' => sprintf(
                    'Debt-to-Equity of %.2f shows moderate leverage. While manageable, continued borrowing without proportional equity growth could push you into higher-risk territory. Consider retaining more earnings to build equity, or explore equity financing for major expansions.',
                    $value
                ),
                'danger' => sprintf(
                    'Debt-to-Equity of %.2f is elevated, indicating the business is heavily reliant on debt financing. This increases financial risk and may make lenders nervous about extending additional credit. Develop a concrete debt reduction plan: (1) prioritize paying down high-interest debt, (2) consider converting some debt to equity, and (3) avoid new borrowings until the ratio improves to below 2.0.',
                    $value
                ),
            ],
            'debt_ratio' => [
                'healthy' => sprintf(
                    'Only %.2f%%%% of your assets are financed through debt. This strong equity position provides a safety cushion against downturns and gives you significant borrowing capacity for future growth investments.',
                    $value * 100
                ),
                'warning' => sprintf(
                    '%.2f%%%% of assets are debt-financed, indicating moderate financial leverage. While not critical, the trend matters more than the absolute number. If this ratio has been increasing over recent periods, it signals growing reliance on debt that should be monitored.',
                    $value * 100
                ),
                'danger' => sprintf(
                    'Over %.2f%%%% of assets are financed by debt — creditors effectively control more than half your asset base. This high leverage increases fixed costs (interest payments) and financial vulnerability. Actions: (1) prioritize debt repayment from operating cash flow, (2) consider asset sales to reduce debt, and (3) build equity through retained earnings.',
                    $value * 100
                ),
            ],
            'interest_coverage_ratio' => [
                'healthy' => sprintf(
                    'Interest Coverage of %.2fx means operating income comfortably covers interest expenses multiple times. This strong coverage gives lenders confidence and provides breathing room even if profits temporarily decline. Consider whether current debt levels are optimal — you may have capacity for strategic borrowing.',
                    $value
                ),
                'warning' => sprintf(
                    'Interest Coverage of %.2fx indicates that operating income covers interest payments but with limited margin. A 20-30%%%% drop in profits could strain debt service. Avoid taking on additional debt until coverage improves to at least 3.0x, and consider refinancing to lower interest rates.',
                    $value
                ),
                'danger' => sprintf(
                    'Interest Coverage of %.2fx is critically low — operating income barely covers interest costs. The business is at risk of default if earnings decline even slightly. Immediate steps: (1) contact lenders to discuss restructuring before missing payments, (2) prioritize debt reduction from all available cash flow, and (3) consider whether any assets can be sold to reduce debt principal.',
                    $value
                ),
            ],
            'asset_turnover' => [
                'healthy' => sprintf(
                    'Asset Turnover of %.2fx demonstrates efficient revenue generation from your asset base. Your operations are well-optimized and capital is being deployed effectively. This operational efficiency is a competitive advantage.',
                    $value
                ),
                'warning' => sprintf(
                    'Asset Turnover of %.2fx is below the 1.5x benchmark. Your assets may not be generating enough sales volume. Review: (1) are all assets fully utilized? (2) can you increase sales without proportionate asset increases? (3) are there obsolete or idle assets tying up capital?',
                    $value
                ),
                'danger' => sprintf(
                    'Asset Turnover of %.2fx indicates low sales relative to invested assets. This often means over-investment in assets or underperformance in sales. A strategic review is needed — consider whether operating lease arrangements could replace owned assets, and evaluate if excess capacity exists that should be reduced.',
                    $value
                ),
            ],
            'inventory_turnover' => [
                'healthy' => sprintf(
                    'Inventory Turnover of %.2fx shows excellent inventory management. Stock moves quickly, minimizing holding costs and reducing obsolescence risk. Your inventory purchasing and demand forecasting are working well.',
                    $value
                ),
                'warning' => sprintf(
                    'Inventory Turnover of %.2fx is below 6x, suggesting some items may be moving slowly. Review inventory aging reports — identify slow-moving items and consider clearance pricing or promotional bundles. Improving turnover will free up cash tied in inventory.',
                    $value
                ),
                'danger' => sprintf(
                    'Inventory moves very slowly at %.2fx turnover. This ties up significant cash in stock and increases holding costs (storage, insurance, spoilage). Immediate actions: (1) run a full inventory aging analysis, (2) heavily discount items over 90 days, (3) review purchasing quantities — consider smaller, more frequent orders, and (4) evaluate your product mix against actual demand patterns.',
                    $value
                ),
            ],
            'accounts_receivable_turnover' => [
                'healthy' => sprintf(
                    'AR Turnover of %.2fx indicates your credit policies and collection processes are effective. Customers are paying promptly, maintaining healthy cash conversion. This efficient receivables management strengthens your working capital position.',
                    $value
                ),
                'warning' => sprintf(
                    'AR Turnover of %.2fx is below 8x, suggesting collections may be slower than ideal. Review aged receivables — if customers are consistently paying late, consider: (1) tightening credit terms, (2) offering small discounts for early payment, and (3) implementing more consistent follow-up on overdue accounts.',
                    $value
                ),
                'danger' => sprintf(
                    'AR collections are very slow at %.2fx turnover. Slow-paying customers are straining your cash flow and increasing bad debt risk. Implement immediate collection measures: (1) contact all overdue accounts within 24 hours, (2) place new orders on hold for overdue customers, (3) require deposits or cash-on-delivery for high-risk customers, and (4) consider selling overdue receivables to a collection agency or factoring company.',
                    $value
                ),
            ],
        ];

        return $messages[$key][$status] ?? 'Review this ratio and consult your financial advisor for guidance.';
    }

    protected function generateAction(string $key, string $status): string
    {
        $actions = [
            'current_ratio' => [
                'healthy' => 'Maintain current liquidity practices. Consider negotiating early-payment discounts with suppliers.',
                'warning' => 'Accelerate accounts receivable collection and review inventory levels to improve working capital.',
                'danger' => 'Immediately review all payables, accelerate collections, and explore a working capital facility or asset liquidation.',
            ],
            'quick_ratio' => [
                'healthy' => 'Use your strong liquidity position to negotiate supplier discounts and optimize payment terms.',
                'warning' => 'Offer limited-time discounts on slow-moving inventory to convert stock to cash more quickly.',
                'danger' => 'Tighten credit terms, implement daily follow-up on overdue accounts, and consider invoice factoring.',
            ],
            'cash_ratio' => [
                'healthy' => 'Maintain cash reserves at current levels. Consider short-term investment of excess cash for better returns.',
                'warning' => 'Review accounts receivable aging weekly and build cash reserves to at least 50% of current liabilities.',
                'danger' => 'Delay non-essential purchases, negotiate payment extensions with suppliers, and explore a business line of credit immediately.',
            ],
            'gross_profit_margin' => [
                'healthy' => 'Consider strategic market share expansion through targeted promotions or new product lines.',
                'warning' => 'Review pricing strategy and supplier contracts. A 3-5% price increase could significantly impact profitability.',
                'danger' => 'Conduct full supplier cost review, evaluate product-level margins, and consider discontinuing low-margin items.',
            ],
            'net_profit_margin' => [
                'healthy' => 'Continue monitoring expense ratios. Consider investing surplus profits in growth initiatives.',
                'warning' => 'Review all operating expenses line by line — target a 2-3% improvement in net margin.',
                'danger' => 'Renegotiate recurring contracts, automate manual processes, and implement departmental cost-control budgets.',
            ],
            'return_on_assets' => [
                'healthy' => 'Benchmark ROA against industry peers. Maintain strong asset utilization practices.',
                'warning' => 'Identify and sell or repurpose underperforming assets to improve overall returns.',
                'danger' => 'Conduct a comprehensive asset utilization review and consider asset-light strategies like leasing.',
            ],
            'return_on_equity' => [
                'healthy' => 'Leverage strong ROE to attract investors or secure favorable financing for expansion.',
                'warning' => 'Optimize capital structure — evaluate whether strategic debt could amplify returns without excessive risk.',
                'danger' => 'Review underperforming product lines, evaluate excess cash distribution to owners, and develop a growth strategy.',
            ],
            'debt_to_equity' => [
                'healthy' => 'Maintain conservative leverage. Consider strategic debt financing for growth opportunities, as lenders view low leverage favorably.',
                'warning' => 'Retain more earnings to build equity and avoid additional borrowing without proportional equity growth.',
                'danger' => 'Prioritize paying down high-interest debt, consider converting debt to equity, and avoid new borrowings until ratio improves.',
            ],
            'debt_ratio' => [
                'healthy' => 'Maintain the strong equity cushion. You have significant borrowing capacity for future investments.',
                'warning' => 'Monitor the trend — if debt ratio has been increasing, implement measures to control further debt accumulation.',
                'danger' => 'Prioritize debt repayment from operating cash flow, consider asset sales to reduce debt, and build equity through retained earnings.',
            ],
            'interest_coverage_ratio' => [
                'healthy' => 'Consider whether current debt levels are optimal — you may have capacity for strategic borrowing at favorable rates.',
                'warning' => 'Avoid additional debt until coverage improves to 3.0x. Consider refinancing to secure lower interest rates.',
                'danger' => 'Contact lenders to discuss restructuring before missing payments. Prioritize debt reduction from all available cash flow.',
            ],
            'asset_turnover' => [
                'healthy' => 'Maintain efficient operations. Document and replicate processes that drive strong asset utilization.',
                'warning' => 'Review asset utilization across all categories. Identify idle or obsolete assets that can be sold or redeployed.',
                'danger' => 'Evaluate operating lease alternatives for owned assets and assess if excess capacity should be reduced.',
            ],
            'inventory_turnover' => [
                'healthy' => 'Maintain current inventory management practices. Monitor for any signs of slowing turnover.',
                'warning' => 'Run inventory aging reports and offer clearance pricing on slow-moving items to free up cash.',
                'danger' => 'Run a full inventory aging analysis, heavily discount items over 90 days, and switch to smaller, more frequent orders.',
            ],
            'accounts_receivable_turnover' => [
                'healthy' => 'Maintain current credit policies. Continue monitoring AR aging to sustain healthy cash conversion.',
                'warning' => 'Tighten credit terms, offer early-payment discounts, and implement consistent follow-up on overdue accounts.',
                'danger' => 'Contact all overdue accounts within 24 hours, place new orders on hold for overdue customers, and require deposits for high-risk customers.',
            ],
        ];

        return $actions[$key][$status] ?? 'Consult your financial advisor for guidance on this ratio.';
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
