<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CreditService;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditController extends Controller
{
    public function __construct(
        protected CreditService $creditService,
        protected ReferralService $referralService,
    ) {}

    public function balance(Request $request): JsonResponse
    {
        $user = $request->user();
        $business = $user?->business;

        $businessCredit = $business ? $this->creditService->getBusinessCredit($business->id) : 0;
        $userCredit = $this->creditService->getUserCredit($user->id);

        return response()->json([
            'business_credit' => $businessCredit,
            'user_credit' => $userCredit,
            'total_credit' => round($businessCredit + $userCredit, 2),
            'currency' => 'USD',
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $business = $user?->business;

        $credits = collect();

        if ($business) {
            $credits = $credits->concat(
                $this->creditService->getHistoryForOwner('business', $business->id)
            );
        }

        $credits = $credits->concat(
            $this->creditService->getHistoryForOwner('user', $user->id)
        );

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $credits = $credits->sortBy($sortBy, SORT_REGULAR, $sortOrder === 'desc')->values();

        return response()->json(['data' => $credits]);
    }
}
