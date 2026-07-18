<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Platform\PlatformAuditService;
use App\Services\Platform\PlatformBusinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class BusinessAccountController extends Controller
{
    public function __construct(
        protected PlatformBusinessService $platformBusinessService,
        protected PlatformAuditService $audit,
    ) {}

    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();
        $business = Business::findOrFail((int) $user->business_id);

        if ((int) $business->owner_id !== (int) $user->id) {
            return response()->json(['message' => 'Only the business owner can delete the business account.'], 403);
        }

        if (!Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'The password you entered is incorrect.'], 422);
        }

        $this->platformBusinessService->resetBusinessData($user, $business);
        $business->delete();
        $user->currentAccessToken()->delete();

        $this->audit->log($user, 'business.account_deleted', 'business', $business->id, 'Self-service account deletion by owner', [
            'business_name' => $business->name,
            'business_slug' => $business->slug,
        ]);

        return response()->json([
            'message' => 'Your business account has been permanently deleted. All associated data has been cleared.',
            'logged_out' => true,
        ]);
    }
}
