<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReferralCollection;
use App\Http\Resources\ReferralResource;
use App\Services\Contracts\ReferralServiceInterface;
use App\Services\Contracts\SubscriptionServiceInterface;
use App\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ReferralController extends Controller
{
    public function __construct(
        protected ReferralServiceInterface $referralService,
        protected SubscriptionServiceInterface $subscriptionService,
        protected CreditService $creditService,
    ) {}

    public function index(): ReferralCollection
    {
        return new ReferralCollection($this->referralService->getAll());
    }

    public function show(int $id): ReferralResource
    {
        $referral = $this->referralService->getById($id);
        if (!$referral) {
            abort(404, 'Referral not found');
        }
        return new ReferralResource($referral);
    }

    public function byBusiness(int $businessId): ReferralCollection
    {
        try {
            return new ReferralCollection($this->referralService->getByBusiness($businessId));
        } catch (RuntimeException $e) {
            abort(404, $e->getMessage());
        }
    }

    public function byCode(int $codeId): ReferralCollection
    {
        try {
            return new ReferralCollection($this->referralService->getByCode($codeId));
        } catch (RuntimeException $e) {
            abort(404, $e->getMessage());
        }
    }

    public function myEarnings(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        try {
            $earnings = $this->referralService->getEarningsByUser($user->id);
            $business = $user->business;

            $businessCredit = $business ? $this->creditService->getBusinessCredit($business->id) : 0;
            $userCredit = $this->creditService->getUserCredit($user->id);

            $earnings['available_credit'] = round($businessCredit + $userCredit, 2);
            $earnings['business_credit'] = $businessCredit;
            $earnings['user_credit'] = $userCredit;
            $earnings['currency'] = 'USD';

            return response()->json($earnings);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function apply(Request $request): JsonResponse
    {
        $request->validate([
            'referral_code' => ['required', 'string', 'max:64'],
            'plan_id' => ['sometimes', 'integer', 'nullable'],
            'billing_cycle' => ['sometimes', 'string', 'in:monthly,yearly', 'nullable'],
        ]);

        $user = $request->user();
        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        $business = $user->business;
        if (!$business) {
            abort(422, 'No business found for this user');
        }

        $subscription = $this->subscriptionService->getByBusiness($business->id);

        try {
            $referral = $this->referralService->processReferral(
                $request->input('referral_code'),
                $subscription?->id,
                $business->id,
                [
                    'plan_id' => $request->input('plan_id'),
                    'billing_cycle' => $request->input('billing_cycle'),
                ]
            );

            return response()->json([
                'message' => 'Referral code applied successfully',
                'referral' => new ReferralResource($referral),
            ]);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }
}
