<?php

declare(strict_types=1);

namespace App\Services\Forecasting;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\Carbon;

class ForecastKpiService
{
    public function __construct(
        protected CashForecastService $cashForecast,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function kpis(
        int $businessId,
        string $mode = 'auto',
        ?string $asOfDate = null,
    ): array {
        $asOf = Carbon::parse($asOfDate ?? now()->toDateString())->startOfDay();
        $mode = in_array($mode, ['auto', 'retail', 'saas'], true) ? $mode : 'auto';

        $hasRecurring = Product::query()
            ->where('business_id', $businessId)
            ->where('is_recurring', true)
            ->exists();

        $resolvedMode = $mode;
        if ($mode === 'auto') {
            $resolvedMode = $hasRecurring ? 'saas' : 'retail';
        }

        $assumptions = [];
        $warnings = [];

        if ($resolvedMode === 'saas') {
            if (! $hasRecurring) {
                $warnings[] = 'SaaS mode requested but no recurring products found; falling back to retail KPIs.';
                $resolvedMode = 'retail';
            }
        }

        $retail = $this->retailKpis($businessId, $asOf, $assumptions, $warnings);
        $saas = null;
        if ($resolvedMode === 'saas') {
            $saas = $this->saasKpis($businessId, $asOf, $assumptions, $warnings);
        }

        $cash = $this->cashForecast->forecast($businessId, $asOf->toDateString(), null, 3);
        $warnings = array_values(array_unique([...$warnings, ...($cash['warnings'] ?? [])]));
        $assumptions = array_values(array_unique([...$assumptions, ...($cash['assumptions'] ?? [])]));

        return [
            'as_of_date' => $asOf->toDateString(),
            'mode' => $mode,
            'resolved_mode' => $resolvedMode,
            'has_recurring_products' => $hasRecurring,
            'retail' => $retail,
            'saas' => $saas,
            'burn' => [
                'monthly_payroll_burn' => $cash['burn']['monthly_payroll_burn'] ?? null,
                'monthly_opex' => $cash['burn']['monthly_opex'] ?? null,
                'monthly_total_burn' => $cash['burn']['monthly_total_burn'] ?? null,
                'coverage' => $cash['coverage'] ?? null,
            ],
            'assumptions' => $assumptions,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<string>  $assumptions
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    protected function retailKpis(int $businessId, Carbon $asOf, array &$assumptions, array &$warnings): array
    {
        $from = $asOf->copy()->subDays(29)->startOfDay();
        $to = $asOf->copy()->endOfDay();

        $sales = Sale::query()
            ->where('business_id', $businessId)
            ->whereBetween('sale_date', [$from, $to])
            ->get(['id', 'total_amount', 'customer_id']);

        $gross = (float) $sales->sum('total_amount');
        $refunds = (float) SaleItem::query()
            ->whereIn('sale_id', $sales->pluck('id'))
            ->sum('refunded_amount');
        $pulse = round(max(0, $gross - $refunds), 2);
        $assumptions[] = 'Pulse is trailing 30-day net sales (gross − refunds).';

        $acquisitionCategoryIds = ExpenseCategory::query()
            ->where('business_id', $businessId)
            ->where(function ($q) {
                $q->where('name', 'like', '%acquisition%')
                    ->orWhere('name', 'like', '%marketing%')
                    ->orWhere('slug', 'like', '%acquisition%')
                    ->orWhere('slug', 'like', '%marketing%');
            })
            ->pluck('id');

        $acquisitionSpend = 0.0;
        if ($acquisitionCategoryIds->isEmpty()) {
            $warnings[] = 'No acquisition/marketing expense categories found; CAC numerator treated as 0.';
        } else {
            $acquisitionSpend = round((float) Expense::query()
                ->where('business_id', $businessId)
                ->whereIn('expense_category_id', $acquisitionCategoryIds)
                ->whereBetween('expense_date', [$from, $to])
                ->sum('amount'), 2);
            $assumptions[] = 'CAC uses trailing 30-day expenses in categories whose name/slug contains acquisition or marketing.';
        }

        $newCustomers = Customer::query()
            ->where('business_id', $businessId)
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $cac = $newCustomers > 0
            ? round($acquisitionSpend / $newCustomers, 2)
            : null;
        if ($newCustomers === 0) {
            $warnings[] = 'No new customers in the last 30 days; CAC is null.';
        }

        $customersWithPurchases = Customer::query()
            ->where('business_id', $businessId)
            ->whereNotNull('last_purchase_at')
            ->get(['id', 'total_purchases', 'last_purchase_at']);

        $ltv = $customersWithPurchases->isEmpty()
            ? 0.0
            : round((float) $customersWithPurchases->avg('total_purchases'), 2);
        $assumptions[] = 'LTV is the average of customer.total_purchases among customers with a purchase history.';

        $churnBase = $customersWithPurchases->filter(function (Customer $c) use ($asOf) {
            return Carbon::parse($c->last_purchase_at)->lt($asOf->copy()->subDays(90));
        });
        // Churn among those who purchased before (have last_purchase_at): % with last purchase older than 90 days
        $churnPct = $customersWithPurchases->isEmpty()
            ? 0.0
            : round(($churnBase->count() / $customersWithPurchases->count()) * 100, 2);
        $assumptions[] = 'Churn is the % of customers with last_purchase_at older than 90 days among those who have purchased.';

        return [
            'pulse_30d_net_sales' => $pulse,
            'cac' => $cac,
            'acquisition_spend_30d' => $acquisitionSpend,
            'new_customers_30d' => $newCustomers,
            'ltv' => $ltv,
            'churn_pct_90d' => $churnPct,
            'customers_with_purchases' => $customersWithPurchases->count(),
            'churned_customers' => $churnBase->count(),
        ];
    }

    /**
     * @param  list<string>  $assumptions
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    protected function saasKpis(int $businessId, Carbon $asOf, array &$assumptions, array &$warnings): array
    {
        $recurringProducts = Product::query()
            ->where('business_id', $businessId)
            ->where('is_recurring', true)
            ->get(['id', 'unit_price', 'name', 'billing_interval']);

        $avgPrice = round((float) $recurringProducts->avg('unit_price'), 2);
        $from = $asOf->copy()->subDays(59)->startOfDay();
        $to = $asOf->copy()->endOfDay();

        $customerIds = SaleItem::query()
            ->whereIn('product_id', $recurringProducts->pluck('id'))
            ->whereHas('sale', function ($q) use ($businessId, $from, $to) {
                $q->where('business_id', $businessId)
                    ->whereBetween('sale_date', [$from, $to])
                    ->whereNotNull('customer_id');
            })
            ->with('sale:id,customer_id')
            ->get()
            ->pluck('sale.customer_id')
            ->filter()
            ->unique()
            ->values();

        $activeSubscribers = $customerIds->count();
        $mrr = round($activeSubscribers * $avgPrice, 2);

        $warnings[] = 'MRR is a simple proxy: distinct customers who bought a recurring product in the last 60 days × average recurring product unit_price.';
        $assumptions[] = 'SaaS MRR proxy does not model churn, upgrades, or prorations.';
        $assumptions[] = 'Recurring product billing_interval defaults to month when null.';

        return [
            'recurring_product_count' => $recurringProducts->count(),
            'avg_recurring_price' => $avgPrice,
            'active_subscribers_60d' => $activeSubscribers,
            'mrr' => $mrr,
            'arr' => round($mrr * 12, 2),
        ];
    }
}
