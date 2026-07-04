<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\FinancialStatementService;
use App\Services\LedgerService;
use App\Services\ReportExportService;
use Illuminate\Http\Request;

class AccountingExportController extends Controller
{
    public function __construct(
        protected FinancialStatementService $financialStatementService,
        protected LedgerService $ledgerService,
        protected ReportExportService $export,
    ) {}

    public function export(Request $request, string $type)
    {
        $request->validate([
            'period_id' => 'required|integer|exists:accounting_periods,id',
            'format' => 'nullable|in:pdf,xlsx,csv',
        ]);

        $business = Business::findOrFail((int) $request->user()->business_id);
        $periodId = (int) $request->query('period_id');
        $format = $request->query('format', 'pdf');

        $method = match ($type) {
            'trial-balance' => 'exportTrialBalance',
            'income-statement' => 'exportIncomeStatement',
            'balance-sheet' => 'exportBalanceSheet',
            'general-ledger' => 'exportGeneralLedger',
            default => null,
        };

        if (!$method) {
            return response()->json(['message' => "Unknown export type: {$type}"], 404);
        }

        return $this->$method($business, $periodId, $format);
    }

    protected function exportTrialBalance(Business $business, int $periodId, string $format)
    {
        $trialBalance = $this->ledgerService->generateTrialBalance($business->id, $periodId);
        $rows = array_map(fn ($r) => [$r['account_code'], $r['account_name'], $r['debit'], $r['credit']], $trialBalance['rows']);
        $rows[] = ['', 'Total', $trialBalance['total_debits'], $trialBalance['total_credits']];

        $headers = ['Account Code', 'Account Name', 'Debit', 'Credit'];
        $filename = $this->export->buildFilename($business, "trial-balance-period-{$periodId}");

        return match ($format) {
            'xlsx' => $this->export->downloadRichXlsx([
                'filename' => $filename,
                'business' => $business,
                'reportTitle' => 'Trial Balance',
                'headers' => $headers,
                'rows' => $rows,
            ]),
            'csv' => $this->export->downloadCsv($filename, $headers, $rows),
            default => $this->export->downloadPdf('accounting-export.trial-balance', [
                'business' => $business,
                'trialBalance' => $trialBalance,
                'formatter' => $this->export,
            ], $filename, 'portrait'),
        };
    }

    protected function exportIncomeStatement(Business $business, int $periodId, string $format)
    {
        $statement = $this->financialStatementService->incomeStatement($business->id, $periodId);

        $rows = [];
        foreach ($statement['revenues'] as $rev) {
            $rows[] = [$rev['account_code'], $rev['account_name'], $rev['balance'], ''];
        }
        $rows[] = ['', 'Total Revenue', $statement['total_revenue'], ''];
        foreach ($statement['expenses'] as $exp) {
            $rows[] = [$exp['account_code'], $exp['account_name'], '', $exp['balance']];
        }
        $rows[] = ['', 'Total Expenses', '', $statement['total_expenses']];
        $rows[] = ['', 'Net Income', '', $statement['net_income']];

        $headers = ['Code', 'Account', 'Revenue', 'Expense'];
        $filename = $this->export->buildFilename($business, "income-statement-period-{$periodId}");

        return match ($format) {
            'xlsx' => $this->export->downloadRichXlsx([
                'filename' => $filename,
                'business' => $business,
                'reportTitle' => 'Income Statement',
                'headers' => $headers,
                'rows' => $rows,
            ]),
            'csv' => $this->export->downloadCsv($filename, $headers, $rows),
            default => $this->export->downloadPdf('accounting-export.income-statement', [
                'business' => $business,
                'statement' => $statement,
                'formatter' => $this->export,
            ], $filename, 'portrait'),
        };
    }

    protected function exportBalanceSheet(Business $business, int $periodId, string $format)
    {
        $sheet = $this->financialStatementService->balanceSheet($business->id, $periodId);

        $rows = [];
        foreach ($sheet['assets'] as $a) {
            $rows[] = [$a['account_code'], $a['account_name'], $a['balance'], '', ''];
        }
        $rows[] = ['', 'Total Assets', $sheet['total_assets'], '', ''];

        $liabilityStart = count($rows);
        foreach ($sheet['liabilities'] as $l) {
            $rows[] = [$l['account_code'], $l['account_name'], '', $l['balance'], ''];
        }
        $rows[] = ['', 'Total Liabilities', '', $sheet['total_liabilities'], ''];

        foreach ($sheet['equities'] as $e) {
            $rows[] = [$e['account_code'], $e['account_name'], '', '', $e['balance']];
        }
        $rows[] = ['', 'Total Equity', '', '', $sheet['total_equity']];
        $rows[] = ['', 'Total Liabilities + Equity', '', '', $sheet['total_liabilities_and_equity']];

        $headers = ['Code', 'Account', 'Assets', 'Liabilities', 'Equity'];
        $filename = $this->export->buildFilename($business, "balance-sheet-period-{$periodId}");

        return match ($format) {
            'xlsx' => $this->export->downloadRichXlsx([
                'filename' => $filename,
                'business' => $business,
                'reportTitle' => 'Balance Sheet',
                'headers' => $headers,
                'rows' => $rows,
            ]),
            'csv' => $this->export->downloadCsv($filename, $headers, $rows),
            default => $this->export->downloadPdf('accounting-export.balance-sheet', [
                'business' => $business,
                'sheet' => $sheet,
                'formatter' => $this->export,
            ], $filename, 'portrait'),
        };
    }

    protected function exportGeneralLedger(Business $business, int $periodId, string $format)
    {
        $ledgerRows = $this->ledgerService->getGeneralLedger($business->id, $periodId);

        $headers = ['Date', 'Entry #', 'Description', 'Account Code', 'Account Name', 'Debit', 'Credit'];
        $rows = array_map(fn ($r) => [
            $r['date'], $r['entry_number'], $r['description'],
            $r['account_code'], $r['account_name'], $r['debit'], $r['credit'],
        ], $ledgerRows);

        $filename = $this->export->buildFilename($business, "general-ledger-period-{$periodId}");

        return match ($format) {
            'xlsx' => $this->export->downloadRichXlsx([
                'filename' => $filename,
                'business' => $business,
                'reportTitle' => 'General Ledger',
                'headers' => $headers,
                'rows' => $rows,
            ]),
            'csv' => $this->export->downloadCsv($filename, $headers, $rows),
            default => $this->export->downloadPdf('accounting-export.general-ledger', [
                'business' => $business,
                'ledgerRows' => $ledgerRows,
                'formatter' => $this->export,
            ], $filename, 'landscape'),
        };
    }
}
