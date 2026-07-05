<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Services\AccountingPeriodService;
use App\Services\FinancialStatementService;
use App\Services\LedgerService;
use App\Services\ReportPeriodResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeneralLedgerController extends Controller
{
    public function __construct(
        protected LedgerService $ledgerService,
        protected FinancialStatementService $financialStatementService,
        protected AccountingPeriodService $accountingPeriodService,
        protected ReportPeriodResolver $reportPeriodResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $ctx = $this->reportPeriodResolver->resolve($businessId, $request);
        $balances = $this->ledgerService->getTrialBalance($businessId, $ctx->snapshotPeriodId);
        $accountId = $request->query('account_id');

        $data = $balances;
        if ($accountId) {
            $data = $balances->where('account_id', (int) $accountId)->values();
        }

        return response()->json(['data' => $data]);
    }

    public function trialBalance(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $ctx = $this->reportPeriodResolver->resolve($businessId, $request);
        $balances = $this->ledgerService->getTrialBalance($businessId, $ctx->snapshotPeriodId);

        $accounts = $balances->map(function ($row) {
            $balance = (float) $row->closing_balance;
            $isDebit = $row->normal_balance === 'debit';

            return [
                'account_id' => $row->account_id,
                'code' => $row->account_code,
                'name' => $row->account_name,
                'type' => $row->account_type_name ?? ($isDebit ? 'Asset' : 'Liability'),
                'debit_balance' => $isDebit ? $balance : 0,
                'credit_balance' => $isDebit ? 0 : $balance,
            ];
        });

        $totalDebits = $accounts->sum('debit_balance');
        $totalCredits = $accounts->sum('credit_balance');

        return response()->json([
            'data' => [
                'accounts' => $accounts,
                'total_debits' => round($totalDebits, 2),
                'total_credits' => round($totalCredits, 2),
                'is_balanced' => abs($totalDebits - $totalCredits) < 0.01,
                'period' => $this->periodPayload($ctx),
            ],
        ]);
    }

    public function profitLoss(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $ctx = $this->reportPeriodResolver->resolve($businessId, $request);

        return response()->json([
            'data' => $this->financialStatementService->incomeStatementForContext($businessId, $ctx),
        ]);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $ctx = $this->reportPeriodResolver->resolve($businessId, $request);

        return response()->json([
            'data' => $this->financialStatementService->balanceSheetForContext($businessId, $ctx),
        ]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $ctx = $this->reportPeriodResolver->resolve($businessId, $request);

        return response()->json([
            'data' => $this->financialStatementService->cashFlowStatementForContext($businessId, $ctx),
        ]);
    }

    public function equity(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $ctx = $this->reportPeriodResolver->resolve($businessId, $request);

        return response()->json([
            'data' => $this->financialStatementService->statementOfEquityForContext($businessId, $ctx),
        ]);
    }

    protected function periodPayload(\App\Support\ReportPeriodContext $ctx): array
    {
        $period = AccountingPeriod::find($ctx->snapshotPeriodId);

        return [
            'id' => $ctx->snapshotPeriodId,
            'name' => $ctx->label,
            'start_date' => $ctx->dateFrom,
            'end_date' => $ctx->dateTo,
            'is_closed' => (bool) ($period?->is_closed ?? false),
            'period_ids' => $ctx->periodIds,
            'is_range' => $ctx->isRange,
        ];
    }
}
