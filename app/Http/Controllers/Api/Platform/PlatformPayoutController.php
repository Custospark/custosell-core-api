<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Services\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformPayoutController extends Controller
{
    public function __construct(
        protected PayoutService $payoutService,
    ) {}

    public function payables(): JsonResponse
    {
        return response()->json(['data' => $this->payoutService->getPayables()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payable_type' => ['required', 'string', Rule::in(['sales_rep', 'user'])],
            'payable_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,pdf,doc,docx'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $attachments = null;
        if ($request->hasFile('attachments')) {
            $attachments = [];
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('payout-attachments', 'public');
                $attachments[] = [
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ];
            }
        }

        $payout = $this->payoutService->recordPayout(
            payableType: $validated['payable_type'],
            payableId: $validated['payable_id'],
            amount: (float) $validated['amount'],
            paymentMethod: $validated['payment_method'] ?? null,
            notes: $validated['notes'] ?? null,
            attachments: $attachments,
            scheduledAt: $validated['scheduled_at'] ?? null,
            paidBy: $request->user()?->id,
        );

        return response()->json(['data' => $payout], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payable_type' => ['required', 'string', Rule::in(['sales_rep', 'user'])],
            'payable_id' => ['required', 'integer'],
        ]);

        return response()->json([
            'data' => $this->payoutService->getPayouts(
                $validated['payable_type'],
                $validated['payable_id'],
            ),
        ]);
    }

    public function updateSchedule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payable_type' => ['required', 'string', Rule::in(['sales_rep', 'user'])],
            'payable_id' => ['required', 'integer'],
            'payout_frequency' => ['nullable', 'string', Rule::in(['weekly', 'biweekly', 'monthly', 'quarterly'])],
            'next_payout_at' => ['nullable', 'date'],
        ]);

        $this->payoutService->updateSchedule(
            $validated['payable_type'],
            $validated['payable_id'],
            $validated['payout_frequency'] ?? null,
            $validated['next_payout_at'] ?? null,
        );

        return response()->json(['message' => 'Schedule updated']);
    }

    public function cancel(int $id): JsonResponse
    {
        $this->payoutService->cancelScheduled($id);
        return response()->json(['message' => 'Payout cancelled']);
    }
}
