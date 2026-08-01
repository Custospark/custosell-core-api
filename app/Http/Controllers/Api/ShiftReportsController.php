<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\BuildsReportResponses;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Services\ReportExportService;
use App\Services\ReportMetricsService;
use Illuminate\Http\Request;

class ShiftReportsController extends Controller
{
    use BuildsReportResponses;

    public function __construct(
        private ReportMetricsService $metrics,
        private ReportExportService $export,
    ) {}

    public function shiftReconciliation(Request $request)
    {
        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $filters = $this->filters($request);
        $business = $this->getBusiness($request);
        $format = $request->query('format', 'pdf');

        $shifts = $this->metrics->shiftReconciliation($business->id, $dateFrom, $dateTo, $filters['shift_id'], $filters['user_id']);

        $headers = ['Shift', 'Cashier', 'Transactions', 'Gross', 'Refunds', 'Expenses', 'Net Sales', 'Cash Handover'];
        $exportRows = array_map(fn ($row) => [
            $row['shift']->clock_in->format('Y-m-d H:i'),
            $row['cashier'],
            $row['transaction_count'],
            $row['gross_sales'],
            $row['refunds'],
            $row['shift_expenses'],
            $row['net_sales'],
            $row['cash_handover'],
        ], $shifts);

        $filename = $this->export->buildFilename($business, 'shift-reconciliation', $dateFrom, $dateTo);

        $summaryCards = [
            ['label' => 'Shifts', 'value' => (string) count($shifts)],
            ['label' => 'Total Handover', 'value' => $this->export->formatMoney(collect($shifts)->sum('cash_handover'), $business->currency), 'tone' => 'positive'],
            ['label' => 'Shift Expenses', 'value' => $this->export->formatMoney(collect($shifts)->sum('shift_expenses'), $business->currency), 'tone' => 'negative'],
            ['label' => 'Transactions', 'value' => (string) collect($shifts)->sum('transaction_count')],
        ];

        return match ($format) {
            'xlsx' => $this->xlsx($business, 'shift-reconciliation', $dateFrom, $dateTo, 'Shift Reconciliation', '#0f766e', $summaryCards, $headers, $exportRows, $this->dateSubtitle($dateFrom, $dateTo), 'Answers: How much cash should be at handover per shift?'),
            'csv' => $this->export->downloadCsv($filename, $headers, $exportRows),
            default => $this->export->downloadPdf('reports.shift-reconciliation', $this->pdfData($request, [
                'shifts' => $shifts,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'accent' => '#0f766e',
                'reportTitle' => 'Shift Reconciliation',
                'reportPurpose' => 'Answers: How much cash should be at handover per shift?',
                'reportSubtitle' => $this->dateSubtitle($dateFrom, $dateTo),
                'summaryCards' => $summaryCards,
            ]), $filename, $this->pdfOrientation('shift-reconciliation')),
        };
    }

    public function shiftClose(Request $request)
    {
        $request->validate(['shift_id' => 'required|integer']);
        $business = $this->getBusiness($request);
        $shiftId = (int) $request->query('shift_id');
        $user = $request->user();

        $shift = Shift::where('business_id', $business->id)->where('id', $shiftId)->first();
        if (! $shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }

        if (! $this->canAccessShiftCloseReport($user, $shift)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $report = $this->metrics->shiftCloseReport($business->id, $shiftId);
        $currency = $business->currency;

        $summaryCards = [
            ['label' => 'Cash at handover', 'value' => $this->export->formatMoney($report['cash_handover'], $currency), 'tone' => 'positive'],
            ['label' => 'Net sales', 'value' => $this->export->formatMoney($report['net_sales'], $currency), 'tone' => 'positive'],
            ['label' => 'Transactions', 'value' => (string) $report['transaction_count']],
            ['label' => 'Shift expenses', 'value' => '-'.$this->export->formatMoney($report['shift_expenses'], $currency), 'tone' => 'negative'],
        ];

        $shift = $report['shift'];
        $subtitle = $shift->clock_out
            ? collect([
                'Closed '.$shift->clock_out->format('M d, Y H:i'),
                $report['duration'] ? 'Duration '.$report['duration'] : null,
            ])->filter()->implode(' · ')
            : 'Started '.$shift->clock_in->format('M d, Y H:i').' · Report as of '.now()->format('M d, Y H:i');

        $filename = $this->export->buildShiftCloseFilename(
            $business,
            $report['cashier'],
            $report['shift']->clock_out,
        );

        return $this->export->downloadPdf('reports.shift-close', $this->pdfData($request, [
            'report' => $report,
            'accent' => '#1e40af',
            'reportTitle' => 'Shift Close Report',
            'reportPurpose' => null,
            'reportSubtitle' => $subtitle,
            'summaryCards' => $summaryCards,
        ]), $filename, 'portrait');
    }

    private function canAccessShiftCloseReport($user, Shift $shift): bool
    {
        if ((int) $shift->business_id !== (int) $user->business_id) {
            return false;
        }

        $moduleAccess = app(\App\Services\ModuleAccessService::class);

        if ($moduleAccess->canAccess($user, 'dashboard')) {
            return true;
        }

        if (! $moduleAccess->canAccess($user, 'sales')) {
            return false;
        }

        if (! $user->hasRolePermission('shifts.close_report')) {
            return false;
        }

        return (int) $shift->user_id === (int) $user->id;
    }
}
