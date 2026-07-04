<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GeneralLedgerResource;
use App\Services\FinancialStatementService;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeneralLedgerController extends Controller
{
    public function __construct(
        protected LedgerService $ledgerService,
        protected FinancialStatementService $financialStatementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $periodId = $request->query('period_id');
        $accountId = $request->query('account_id');

        if (!$periodId) {
            return response()->json(['message' => 'period_id is required'], 422);
        }

        $balances = $this->ledgerService->getTrialBalance($businessId, (int) $periodId);

        $data = $balances;
        if ($accountId) {
            $data = $balances->where('account_id', (int) $accountId)->values();
        }

        return response()->json(['data' => $data]);
    }

    public function trialBalance(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $periodId = $request->query('period_id');

        if (!$periodId) {
            return response()->json(['message' => 'period_id is required'], 422);
        }

        $balances = $this->ledgerService->getTrialBalance($businessId, (int) $periodId);

        $totalDebits = $balances->sum('total_debits') + $balances->sum('opening_balance');
        $totalCredits = $balances->sum('total_credits') + $balances->sum('opening_balance');

        return response()->json([
            'data' => $balances,
            'totals' => [
                'total_debits' => round($totalDebits, 2),
                'total_credits' => round($totalCredits, 2),
                'difference' => round($totalDebits - $totalCredits, 2),
            ],
        ]);
    }

    public function profitLoss(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $periodId = $request->query('period_id');

        if (!$periodId) {
            return response()->json(['message' => 'period_id is required'], 422);
        }

        return response()->json(
            $this->financialStatementService->getIncomeStatement($businessId, (int) $periodId)
        );
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $periodId = $request->query('period_id');

        if (!$periodId) {
            return response()->json(['message' => 'period_id is required'], 422);
        }

        return response()->json(
            $this->financialStatementService->getBalanceSheet($businessId, (int) $periodId)
        );
    }
}
