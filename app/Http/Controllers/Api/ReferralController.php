<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReferralCollection;
use App\Http\Resources\ReferralResource;
use App\Services\Contracts\ReferralServiceInterface;
use RuntimeException;

class ReferralController extends Controller
{
    public function __construct(
        protected ReferralServiceInterface $referralService,
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
}
