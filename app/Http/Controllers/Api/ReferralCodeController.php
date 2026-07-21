<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReferralCodeRequest;
use App\Http\Resources\ReferralCodeCollection;
use App\Http\Resources\ReferralCodeResource;
use App\Models\ReferralCode;
use App\Services\Contracts\ReferralCodeServiceInterface;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ReferralCodeController extends Controller
{
    public function __construct(
        protected ReferralCodeServiceInterface $referralCodeService,
    ) {}

    public function index(): ReferralCodeCollection
    {
        return new ReferralCodeCollection(ReferralCode::paginate(15));
    }

    public function show(int $id): ReferralCodeResource
    {
        $referralCode = $this->referralCodeService->getById($id);
        if (!$referralCode) {
            abort(404, 'Referral code not found');
        }
        return new ReferralCodeResource($referralCode);
    }

    public function store(ReferralCodeRequest $request): JsonResponse
    {
        $referralCode = $this->referralCodeService->create($request->validated());
        return response()->json(new ReferralCodeResource($referralCode), 201);
    }

    public function update(ReferralCodeRequest $request, int $id): ReferralCodeResource
    {
        try {
            $referralCode = $this->referralCodeService->update($id, $request->validated());
            return new ReferralCodeResource($referralCode);
        } catch (RuntimeException $e) {
            abort(404, $e->getMessage());
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->referralCodeService->delete($id);
            return response()->json(['message' => 'Deleted'], 200);
        } catch (RuntimeException $e) {
            abort(404, $e->getMessage());
        }
    }
}
