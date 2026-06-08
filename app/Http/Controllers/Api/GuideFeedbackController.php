<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GuideFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GuideFeedbackController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = GuideFeedback::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $items->map(fn (GuideFeedback $r) => $this->serializeMine($r))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', Rule::in(GuideFeedback::allowedCategories())],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        $user = $request->user();

        $row = new GuideFeedback([
            'user_id' => $user->id,
            'business_id' => $user->business_id,
            'category' => $data['category'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'status' => GuideFeedback::STATUS_SUBMITTED,
        ]);
        $row->save();

        return response()->json([
            'data' => $this->serializeMine($row->fresh()),
            'message' => 'Thank you — we received your submission.',
        ], 201);
    }

    /** @return array<string, mixed> */
    private function serializeMine(GuideFeedback $r): array
    {
        return [
            'id' => $r->id,
            'uuid' => $r->uuid,
            'category' => $r->category,
            'subject' => $r->subject,
            'body' => $r->body,
            'status' => $r->status,
            'staff_reply' => $r->staff_reply,
            'created_at' => $r->created_at?->toIso8601String(),
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
    }
}
