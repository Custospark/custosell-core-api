<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReferralCodeRequest;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Services\Contracts\ReferralCodeServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformReferralCodeController extends Controller
{
    public function __construct(
        protected ReferralCodeServiceInterface $referralCodeService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ReferralCode::with(['ownerUser'])->orderBy('created_at', 'desc');

        if ($request->filled('owner_type')) {
            $query->where('owner_type', $request->owner_type);
        }

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($w) use ($q) {
                $w->where('code', 'like', "%{$q}%")
                  ->orWhereHas('ownerUser', fn ($u) => $u->where('name', 'like', "%{$q}%"));
            });
        }

        $codes = $request->boolean('paginate', true)
            ? $query->paginate($request->integer('per_page', 20))
            : $query->get();

        $codes->loadCount(['referrals as usage_count']);

        return response()->json([
            'data' => $codes,
        ]);
    }

    public function store(ReferralCodeRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (!isset($data['code']) || empty($data['code'])) {
            $data['code'] = $this->referralCodeService->generateCode();
        }

        // Auto-assign owner_user_id for personal business codes (no specific business owner)
        if (!isset($data['owner_user_id']) && !isset($data['owner_business_id']) && $request->user()) {
            $data['owner_user_id'] = $request->user()->id;
        }

        $code = $this->referralCodeService->create($data);

        return response()->json(['data' => $code], 201);
    }

    public function show(int $id): JsonResponse
    {
        $code = ReferralCode::withCount('referrals')->findOrFail($id);
        return response()->json(['data' => $code]);
    }

    public function update(ReferralCodeRequest $request, int $id): JsonResponse
    {
        $code = $this->referralCodeService->update($id, $request->validated());
        return response()->json(['data' => $code]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->referralCodeService->delete($id);
        return response()->json(['message' => 'Referral code deleted']);
    }

    public function usage(int $id): JsonResponse
    {
        $code = ReferralCode::findOrFail($id);
        $referrals = Referral::where('referral_code_id', $id)
            ->with('referredBusiness')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => [
                'code' => $code,
                'usage_count' => $referrals->count(),
                'active_count' => $referrals->where('status', 'active')->count(),
                'total_discount_given' => (float) $referrals->sum('discount_applied'),
                'referrals' => $referrals->toArray(),
            ],
        ]);
    }
}
