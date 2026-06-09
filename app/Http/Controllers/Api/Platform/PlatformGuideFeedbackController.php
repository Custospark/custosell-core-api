<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\GuideFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformGuideFeedbackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(GuideFeedback::allowedStatuses())],
            'category' => ['nullable', 'string', Rule::in(GuideFeedback::allowedCategories())],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        $query = GuideFeedback::query()
            ->with(['user:id,name,email', 'business:id,name'])
            ->orderByDesc('created_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        if (! empty($validated['q'])) {
            $term = '%'.$validated['q'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('subject', 'like', $term)->orWhere('body', 'like', $term);
            });
        }

        $items = $query->limit(500)->get();

        return response()->json([
            'data' => $items->map(fn (GuideFeedback $r) => $this->serializeAdminRow($r))->values(),
        ]);
    }

    public function show(Request $request, GuideFeedback $guideFeedback): JsonResponse
    {
        $guideFeedback->load(['user:id,name,email', 'business:id,name']);

        return response()->json([
            'data' => $this->serializeAdminDetail($guideFeedback),
        ]);
    }

    public function update(Request $request, GuideFeedback $guideFeedback): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(GuideFeedback::allowedStatuses())],
            'staff_reply' => ['nullable', 'string', 'max:20000'],
            'admin_internal_notes' => ['nullable', 'string', 'max:20000'],
        ]);

        $guideFeedback->fill($data);
        $guideFeedback->save();

        $guideFeedback->load(['user:id,name,email', 'business:id,name']);

        return response()->json([
            'data' => $this->serializeAdminDetail($guideFeedback),
            'message' => 'Feedback updated successfully.',
        ]);
    }

    public function destroy(GuideFeedback $guideFeedback): JsonResponse
    {
        $guideFeedback->delete();

        return response()->json(['message' => 'Feedback deleted.']);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:guide_feedback,id'],
        ]);

        $deleted = GuideFeedback::query()->whereIn('id', $data['ids'])->delete();

        return response()->json([
            'message' => "{$deleted} submission(s) deleted.",
            'deleted' => $deleted,
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeAdminRow(GuideFeedback $r): array
    {
        return [
            'id' => $r->id,
            'uuid' => $r->uuid,
            'user_id' => $r->user_id,
            'user_display' => $r->user?->name ?? ('User #'.$r->user_id),
            'user_email' => $r->user?->email,
            'business_id' => $r->business_id,
            'business_name' => $r->business?->name,
            'category' => $r->category,
            'subject' => $r->subject,
            'status' => $r->status,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeAdminDetail(GuideFeedback $r): array
    {
        return [
            'id' => $r->id,
            'uuid' => $r->uuid,
            'user_id' => $r->user_id,
            'user_display' => $r->user?->name ?? ('User #'.$r->user_id),
            'user_email' => $r->user?->email,
            'business_id' => $r->business_id,
            'business_name' => $r->business?->name,
            'category' => $r->category,
            'subject' => $r->subject,
            'body' => $r->body,
            'status' => $r->status,
            'staff_reply' => $r->staff_reply,
            'admin_internal_notes' => $r->admin_internal_notes,
            'created_at' => $r->created_at?->toIso8601String(),
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
    }
}
