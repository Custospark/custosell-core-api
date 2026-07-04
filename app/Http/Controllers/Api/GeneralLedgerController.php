<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountingPeriodService;
use App\Services\FinancialStatementService;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeneralLedgerController extends Controller
{
    public function __construct(
        protected LedgerService $ledgerService,
        protected FinancialStatementService $financialStatementService,
        protected AccountingPeriodService $accountingPeriodService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $periodId = $this->resolvePeriodId($request);
        $accountId = $request->query('account_id');

        $balances = $this->ledgerService->getTrialBalance($businessId, $periodId);

        $data = $balances;
        if ($accountId) {
            $data = $balances->where('account_id', (int) $accountId)->values();
        }

        return response()->json(['data' => $data]);
    }

    protected function resolvePeriodId(Request $request): int
    {
        $periodId = $request->query('period_id');
        if ($periodId) {
            return (int) $periodId;
        }
        $businessId = $request->user()->business_id;
        $current = $this->accountingPeriodService->getCurrentPeriod($businessId);
        return $current->id;
    }

    public function trialBalance(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $periodId = $this->resolvePeriodId($request);
        $balances = $this->ledgerService->getTrialBalance($businessId, $periodId);

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
        $period = \App\Models\AccountingPeriod::find($periodId);

        return response()->json([
            'data' => [
                'accounts' => $accounts,
                'total_debits' => round($totalDebits, 2),
                'total_credits' => round($totalCredits, 2),
                'is_balanced' => abs($totalDebits - $totalCredits) < 0.01,
                'period' => $period ? [
                    'id' => $period->id,
                    'name' => $period->name,
                    'start_date' => $period->start_date->toDateString(),
                    'end_date' => $period->end_date->toDateString(),
                    'is_closed' => $period->is_closed,
                ] : null,
            ],
        ]);
    }

    public function profitLoss(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $periodId = $this->resolvePeriodId($request);
        $result = $this->financialStatementService->incomeStatement($businessId, $periodId);
        return response()->json(['data' => $result]);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $periodId = $this->resolvePeriodId($request);
        $result = $this->financialStatementService->balanceSheet($businessId, $periodId);
        return response()->json(['data' => $result]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $periodId = $this->resolvePeriodId($request);
        $result = $this->financialStatementService->cashFlowStatement($businessId, $periodId);
        return response()->json(['data' => $result]);
    }

    public function equity(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $periodId = $this->resolvePeriodId($request);
        $result = $this->financialStatementService->statementOfEquity($businessId, $periodId);
        return response()->json(['data' => $result]);
    }
}
