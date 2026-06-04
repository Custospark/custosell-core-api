<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Business;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Barryvdh\DomPDF\Facade\Pdf;

class ReportsController extends Controller
{
    private function getDateRange(Request $request): array
    {
        $dateFrom = $request->query('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->query('date_to', now()->toDateString());
        return [$dateFrom, $dateTo];
    }

    private function businessId(Request $request): int
    {
        return (int) $request->user()->business_id;
    }

    private function getBusiness(Request $request): Business
    {
        return Business::findOrFail($this->businessId($request));
    }

    private function pdfData(Request $request, array $extra = []): array
    {
        return array_merge(['business' => $this->getBusiness($request)], $extra);
    }

    private function downloadCsv(string $filename, array $headers, array $rows)
    {
        $csv = implode("\n", array_map(fn($r) => implode(',', array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $r)), array_merge([$headers], $rows)));
        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.csv"',
        ]);
    }

    private function downloadXlsx(string $filename, array $headers, array $rows)
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Report');
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(chr(65 + $i) . '1', $h);
            $sheet->getColumnDimension(chr(65 + $i))->setAutoSize(true);
        }
        foreach ($rows as $r => $row) {
            foreach ($row as $i => $v) {
                $sheet->setCellValue(chr(65 + $i) . ($r + 2), $v);
            }
        }
        $writer = new Xlsx($spreadsheet);
        $temp = tempnam(sys_get_temp_dir(), 'rpt');
        $writer->save($temp);
        return response()->download($temp, $filename . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function downloadPdf(string $view, array $data, string $filename)
    {
        $pdf = Pdf::loadView($view, $data);
        return $pdf->download($filename . '.pdf');
    }

    public function dailySales(Request $request)
    {
        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $sales = Sale::where('business_id', $this->businessId($request))
            ->whereDate('sale_date', '>=', $dateFrom)->whereDate('sale_date', '<=', $dateTo)
            ->with('saleItems')->orderBy('sale_date')->get();
        $format = $request->query('format', 'pdf');

        $headers = ['Receipt', 'Date', 'Items', 'Payment', 'Total'];
        $rows = $sales->map(fn($s) => [
            $s->receipt_number, $s->sale_date->format('Y-m-d'),
            $s->saleItems->count(), ucfirst(str_replace('_', ' ', $s->payment_method)),
            (float) $s->total_amount,
        ])->toArray();
        $rows[] = ['', '', '', 'Total', (float) $sales->sum('total_amount')];

        return match ($format) {
            'xlsx' => $this->downloadXlsx('daily-sales', $headers, $rows),
            'csv' => $this->downloadCsv('daily-sales', $headers, $rows),
            default => $this->downloadPdf('reports.daily-sales', $this->pdfData($request, compact('sales', 'dateFrom', 'dateTo')), 'daily-sales'),
        };
    }

    public function salesTrend(Request $request)
    {
        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $businessId = $this->businessId($request);
        $format = $request->query('format', 'pdf');

        $trend = [];
        $current = \Carbon\Carbon::parse($dateFrom);
        $end = \Carbon\Carbon::parse($dateTo);
        while ($current->lte($end)) {
            $date = $current->format('Y-m-d');
            $daySales = Sale::where('business_id', $businessId)->whereDate('sale_date', $date)->get();
            $trend[] = ['date' => $date, 'revenue' => (float) $daySales->sum('total_amount'), 'transactions' => $daySales->count()];
            $current->addDay();
        }

        $headers = ['Date', 'Revenue', 'Transactions'];
        $rows = array_map(fn($t) => [$t['date'], $t['revenue'], $t['transactions']], $trend);
        $rows[] = ['Total', collect($trend)->sum('revenue'), collect($trend)->sum('transactions')];

        return match ($format) {
            'xlsx' => $this->downloadXlsx('sales-trend', $headers, $rows),
            'csv' => $this->downloadCsv('sales-trend', $headers, $rows),
            default => $this->downloadPdf('reports.sales-trend', $this->pdfData($request, compact('trend', 'dateFrom', 'dateTo')), 'sales-trend'),
        };
    }

    public function expenses(Request $request)
    {
        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $expenses = Expense::where('business_id', $this->businessId($request))
            ->whereDate('expense_date', '>=', $dateFrom)->whereDate('expense_date', '<=', $dateTo)
            ->with('expenseCategory')->orderBy('expense_date')->get();
        $format = $request->query('format', 'pdf');

        $headers = ['Date', 'Category', 'Description', 'Amount'];
        $rows = $expenses->map(fn($e) => [
            $e->expense_date instanceof \Carbon\Carbon ? $e->expense_date->format('Y-m-d') : $e->expense_date,
            $e->expenseCategory?->name ?? '—', $e->description, (float) $e->amount,
        ])->toArray();
        $rows[] = ['', '', 'Total', (float) $expenses->sum('amount')];

        return match ($format) {
            'xlsx' => $this->downloadXlsx('expenses', $headers, $rows),
            'csv' => $this->downloadCsv('expenses', $headers, $rows),
            default => $this->downloadPdf('reports.expenses', $this->pdfData($request, compact('expenses', 'dateFrom', 'dateTo')), 'expenses'),
        };
    }

    public function inventory(Request $request)
    {
        $products = Product::where('business_id', $this->businessId($request))
            ->with('category')->orderBy('name')->get();
        $format = $request->query('format', 'xlsx');

        $headers = ['Product', 'Category', 'Stock', 'Threshold', 'Unit Price'];
        $rows = $products->map(fn($p) => [
            $p->name, $p->category?->name ?? '—', $p->stock_quantity,
            $p->low_stock_threshold, (float) $p->unit_price,
        ])->toArray();

        return match ($format) {
            'csv' => $this->downloadCsv('inventory', $headers, $rows),
            'pdf' => $this->downloadPdf('reports.inventory', $this->pdfData($request, compact('products')), 'inventory'),
            default => $this->downloadXlsx('inventory', $headers, $rows),
        };
    }

    public function paymentBreakdown(Request $request)
    {
        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $sales = Sale::where('business_id', $this->businessId($request))
            ->whereDate('sale_date', '>=', $dateFrom)->whereDate('sale_date', '<=', $dateTo)
            ->get();
        $format = $request->query('format', 'csv');

        $breakdown = $sales->groupBy('payment_method')->map(fn($group, $method) => [
            'method' => $method, 'count' => $group->count(), 'total' => (float) $group->sum('total_amount'),
        ])->values()->toArray();

        $headers = ['Method', 'Transactions', 'Total'];
        $rows = array_map(fn($b) => [ucfirst(str_replace('_', ' ', $b['method'])), $b['count'], $b['total']], $breakdown);
        $rows[] = ['Total', collect($breakdown)->sum('count'), collect($breakdown)->sum('total')];

        return match ($format) {
            'xlsx' => $this->downloadXlsx('payment-breakdown', $headers, $rows),
            'pdf' => $this->downloadPdf('reports.payment-breakdown', $this->pdfData($request, compact('breakdown', 'dateFrom', 'dateTo')), 'payment-breakdown'),
            default => $this->downloadCsv('payment-breakdown', $headers, $rows),
        };
    }
}
