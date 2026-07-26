<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\BillingCredit;
use App\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformCreditController extends Controller
{
    public function __construct(
        protected CreditService $creditService,
        protected ReferralService $referralService,
    ) {}

    public function index(): JsonResponse
    {
        $credits = $this->creditService->getAllCredits();

        return response()->json([
            'data' => $credits->map(fn ($c) => [
                'id' => $c->id,
                'owner_type' => $c->owner_type,
                'owner_id' => $c->owner_id,
                'referral_id' => $c->referral_id,
                'amount' => (float) $c->amount,
                'amount_used' => (float) $c->amount_used,
                'amount_remaining' => $c->amount_remaining,
                'status' => $c->status,
                'created_at' => $c->created_at,
                'referred_business' => $c->referral?->referredBusiness?->only(['id', 'name']),
            ]),
            'totals' => [
                'total_outstanding' => $credits->sum(fn ($c) => $c->amount_remaining),
                'total_fully_used' => $credits->where('status', 'fully_used')->count(),
                'total_available' => $credits->where('status', 'available')->count(),
            ],
        ]);
    }

    public function pendingPayouts(): JsonResponse
    {
        $pending = $this->creditService->getPendingPayouts();

        return response()->json([
            'data' => $pending->map(fn ($c) => [
                'id' => $c->id,
                'user_id' => $c->owner_id,
                'amount' => (float) $c->amount,
                'amount_remaining' => $c->amount_remaining,
                'created_at' => $c->created_at,
                'referral_code' => $c->referral?->referralCode?->code,
            ]),
            'total_pending' => $pending->sum(fn ($c) => $c->amount_remaining),
        ]);
    }

    public function recordPayout(Request $request, int $creditId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $credit = BillingCredit::find($creditId);
        if (!$credit) {
            abort(404, 'Credit not found');
        }

        $amount = (float) $validated['amount'];
        if ($amount > $credit->amount_remaining) {
            abort(422, 'Payout amount exceeds remaining credit');
        }

        $newUsed = (float) $credit->amount_used + $amount;
        $newStatus = $newUsed >= (float) $credit->amount ? 'fully_used' : 'partially_used';

        $credit->update([
            'amount_used' => $newUsed,
            'status' => $newStatus,
        ]);

        return response()->json([
            'message' => 'Payout recorded successfully',
            'credit_id' => $credit->id,
            'amount_paid' => $amount,
            'remaining' => $credit->amount_remaining,
            'status' => $newStatus,
        ]);
    }
}
