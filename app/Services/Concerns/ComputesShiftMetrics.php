<?php

namespace App\Services\Concerns;

use App\Models\Business;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Services\ReportExportService;

trait ComputesShiftMetrics
{
    public function shiftReconciliation(int $businessId, string $dateFrom, string $dateTo, ?int $shiftId = null, ?int $userId = null): array
    {
        $query = Shift::where('business_id', $businessId)
            ->whereDate('clock_in', '>=', $dateFrom)
            ->whereDate('clock_in', '<=', $dateTo)
            ->with('user')
            ->orderByDesc('clock_in');

        if ($shiftId) {
            $query->where('id', $shiftId);
        }
        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->get()->map(function (Shift $shift) use ($businessId) {
            $sales = Sale::where('business_id', $businessId)
                ->where('shift_id', $shift->id)
                ->with('saleItems')
                ->get();

            $gross = (float) $sales->sum('total_amount');
            $refunds = (float) SaleItem::whereIn('sale_id', $sales->pluck('id'))->sum('refunded_amount');
            $netAfterRefunds = max(0, $gross - $refunds);

            $cash = 0.0;
            $mobile = 0.0;
            $cardOther = 0.0;
            foreach ($sales as $sale) {
                $net = $this->saleNetAfterRefunds($sale);
                $method = $this->normalizePaymentMethod($sale->payment_method);
                match ($method) {
                    'cash' => $cash += $net,
                    'mobile_money' => $mobile += $net,
                    default => $cardOther += $net,
                };
            }

            $shiftExpenses = (float) Expense::where('business_id', $businessId)
                ->where('shift_id', $shift->id)
                ->sum('amount');
            $netSales = max(0, $gross - $refunds - $shiftExpenses);
            $openingBalance = (float) ($shift->opening_balance ?? 0);
            // Canonical (docs/shift-sales-formulas.md):
            //   cash_collected    = cash − expenses
            //   cash_at_handover  = opening_balance + cash_collected   (= expected_cash)
            $cashCollected = max(0, $cash - $shiftExpenses);
            $cashAtHandover = $openingBalance + $cashCollected;
            $countedCash = $shift->counted_cash !== null ? (float) $shift->counted_cash : null;

            return [
                'shift' => $shift,
                'cashier' => $shift->user?->name ?? '-',
                'transaction_count' => $sales->count(),
                'gross_sales' => $gross,
                'refunds' => $refunds,
                'net_after_refunds' => $netAfterRefunds,
                'net_sales' => $netSales,
                'shift_expenses' => $shiftExpenses,
                'cash' => $cash,
                'mobile_money' => $mobile,
                'card_other' => $cardOther,
                'cash_collected' => $cashCollected,
                'opening_balance' => $openingBalance,
                'counted_cash' => $countedCash,
                'expected_cash' => $cashAtHandover,
                'cash_handover' => $cashAtHandover,
                'variance' => $countedCash !== null ? $countedCash - $cashAtHandover : null,
            ];
        })->all();
    }

    /**
     * Single-shift close report for cashier handover (PDF).
     *
     * @return array{
     *   shift: Shift,
     *   cashier: string,
     *   branch: string|null,
     *   duration: string|null,
     *   transaction_count: int,
     *   gross_sales: float,
     *   refunds: float,
     *   net_after_refunds: float,
     *   net_sales: float,
     *   shift_expenses: float,
     *   cash: float,
     *   mobile_money: float,
     *   card_other: float,
     *   cash_handover: float,
     *   opening_balance: float,
     *   counted_cash: float|null,
     *   expected_cash: float,
     *   variance: float|null
     * }
     */
    public function shiftCloseReport(int $businessId, int $shiftId): array
    {
        $shift = Shift::where('business_id', $businessId)
            ->where('id', $shiftId)
            ->with(['user', 'location'])
            ->firstOrFail();

        $reconciliation = $this->shiftReconciliation(
            $businessId,
            $shift->clock_in->format('Y-m-d'),
            $shift->clock_in->format('Y-m-d'),
            $shiftId,
            null,
        );

        $metrics = $reconciliation[0] ?? [
            'transaction_count' => 0,
            'gross_sales' => 0.0,
            'refunds' => 0.0,
            'net_after_refunds' => 0.0,
            'net_sales' => 0.0,
            'shift_expenses' => 0.0,
            'cash' => 0.0,
            'mobile_money' => 0.0,
            'card_other' => 0.0,
            'cash_collected' => 0.0,
            'opening_balance' => 0.0,
            'counted_cash' => null,
            'expected_cash' => 0.0,
            'variance' => null,
        ];

        $branch = $shift->location?->name
            ?? Business::where('id', $businessId)->first()?->defaultLocation?->name
            ?? null;

        return [
            'shift' => $shift,
            'cashier' => $shift->user?->name ?? '-',
            'branch' => $branch,
            'duration' => $this->formatShiftDuration($shift),
            'transaction_count' => (int) $metrics['transaction_count'],
            'gross_sales' => (float) $metrics['gross_sales'],
            'refunds' => (float) $metrics['refunds'],
            'net_after_refunds' => (float) $metrics['net_after_refunds'],
            'net_sales' => (float) $metrics['net_sales'],
            'shift_expenses' => (float) $metrics['shift_expenses'],
            'cash' => (float) $metrics['cash'],
            'mobile_money' => (float) $metrics['mobile_money'],
            'card_other' => (float) $metrics['card_other'],
            'cash_collected' => (float) $metrics['cash_collected'],
            'opening_balance' => (float) $metrics['opening_balance'],
            'counted_cash' => $metrics['counted_cash'] !== null ? (float) $metrics['counted_cash'] : null,
            'expected_cash' => (float) $metrics['expected_cash'],
            'cash_handover' => (float) $metrics['cash_handover'] ?? (float) $metrics['expected_cash'],
            'variance' => $metrics['variance'] !== null ? (float) $metrics['variance'] : null,
        ];
    }

    public function formatShiftDuration(Shift $shift): ?string
    {
        if (! $shift->clock_out) {
            return null;
        }

        $minutes = $shift->clock_in->diffInMinutes($shift->clock_out);
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($hours > 0) {
            return "{$hours}h {$mins}m";
        }

        return "{$mins}m";
    }

    public function plSummaryCards(array $summary, string $currency, ReportExportService $formatter): array
    {
        return [
            ['label' => 'Gross Sales', 'value' => $formatter->formatMoney($summary['gross_sales'], $currency)],
            ['label' => 'Refunds', 'value' => '-'.$formatter->formatMoney($summary['refunds'], $currency), 'tone' => 'negative'],
            ['label' => 'Expenses', 'value' => '-'.$formatter->formatMoney($summary['expenses'], $currency), 'tone' => 'negative'],
            ['label' => 'Net Sales', 'value' => $formatter->formatMoney($summary['net_sales'], $currency), 'tone' => 'positive'],
        ];
    }

    /** @return list<string> */
    public function insightLines(array $insights, string $currency, ReportExportService $formatter): array
    {
        $lines = [];

        if (! empty($insights['best_day'])) {
            $lines[] = 'Best day: '.$insights['best_day']['date'].' - '.$formatter->formatMoney($insights['best_day']['net_sales'], $currency).' net sales';
        }
        if (! empty($insights['worst_day'])) {
            $lines[] = 'Weakest day: '.$insights['worst_day']['date'].' - '.$formatter->formatMoney($insights['worst_day']['net_sales'], $currency).' net sales';
        }
        if (isset($insights['refund_rate_pct'], $insights['expense_ratio_pct'])) {
            $lines[] = 'Refund rate: '.$insights['refund_rate_pct'].'% | Expense ratio: '.$insights['expense_ratio_pct'].'% of gross';
        }
        if (isset($insights['avg_net_sales'])) {
            $lines[] = 'Average daily net sales: '.$formatter->formatMoney((float) $insights['avg_net_sales'], $currency);
        }

        return $lines;
    }
}
