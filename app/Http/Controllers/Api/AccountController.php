<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesRep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function paymentInfo(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'data' => [
                'payment_method' => $user->payment_method,
                'mobile_money_provider' => $user->mobile_money_provider,
                'mobile_money_number' => $user->mobile_money_number,
                'bank_name' => $user->bank_name,
                'bank_account_name' => $user->bank_account_name,
                'bank_account_number' => $user->bank_account_number,
                'bank_branch' => $user->bank_branch,
            ],
        ]);
    }

    public function updatePaymentInfo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['nullable', 'string', 'in:mobile_money,bank'],
            'mobile_money_provider' => ['nullable', 'string', 'max:50'],
            'mobile_money_number' => ['nullable', 'string', 'max:30'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account_name' => ['nullable', 'string', 'max:100'],
            'bank_account_number' => ['nullable', 'string', 'max:50'],
            'bank_branch' => ['nullable', 'string', 'max:100'],
        ]);

        $request->user()->update($validated);

        return response()->json(['message' => 'Payment info updated successfully']);
    }

    public function payoutHistory(Request $request): JsonResponse
    {
        $user = $request->user();
        $payouts = $user->payouts()
            ->with('paidByUser')
            ->orderBy('created_at', 'desc')
            ->get();

        // Sales-rep commissions are recorded as payouts on the SalesRep model,
        // not the user. Merge them in so reps see their full payout history.
        $salesRep = SalesRep::where('user_id', $user->id)->first();
        if ($salesRep) {
            $repPayouts = $salesRep->payouts()
                ->with('paidByUser')
                ->orderBy('created_at', 'desc')
                ->get();
            $payouts = $payouts->concat($repPayouts)->sortByDesc('created_at')->values();
        }

        $payouts = $payouts->map(function ($payout) {
            $payoutArray = $payout->toArray();
            $payoutArray['attachments'] = $this->normalizePayoutAttachments($payoutArray['attachments'] ?? null);
            return $payoutArray;
        });

        return response()->json(['data' => $payouts->toArray()]);
    }

    private function normalizePayoutAttachments(mixed $attachments): ?array
    {
        if (empty($attachments)) return null;
        if (is_string($attachments)) {
            $attachments = json_decode($attachments, true) ?? [];
        }
        if (!is_array($attachments)) return null;

        return array_map(function ($att) {
            if (is_array($att)) {
                $att['file_url'] = !empty($att['path']) ? url('storage/' . ltrim($att['path'], '/')) : null;
            }
            return $att;
        }, $attachments);
    }
}
