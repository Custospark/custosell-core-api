<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\GuideFaq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformGuideFaqController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_published' => 'nullable|boolean',
            'include_trash' => 'nullable|boolean',
        ]);

        $query = GuideFaq::query()->orderBy('sort_order')->orderBy('id');

        if (array_key_exists('is_published', $validated) && $validated['is_published'] !== null) {
            $query->where('is_published', (bool) $validated['is_published']);
        }

        if (! empty($validated['include_trash'])) {
            $query->withTrashed();
        }

        $items = $query->get();

        return response()->json([
            'data' => $items->map(fn (GuideFaq $faq) => $this->serialize($faq))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedPayload($request);

        $faq = new GuideFaq($data);
        $faq->created_by = $request->user()?->id;
        $faq->save();

        return response()->json([
            'data' => $this->serialize($faq->fresh()),
            'message' => 'FAQ created successfully.',
        ], 201);
    }

    public function update(Request $request, GuideFaq $guideFaq): JsonResponse
    {
        $guideFaq->fill($this->validatedPayload($request, isUpdate: true));
        $guideFaq->save();

        return response()->json([
            'data' => $this->serialize($guideFaq->fresh()),
            'message' => 'FAQ updated successfully.',
        ]);
    }

    public function destroy(GuideFaq $guideFaq): JsonResponse
    {
        $guideFaq->delete();

        return response()->json([
            'data' => null,
            'message' => 'FAQ archived successfully.',
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(GuideFaq $faq): array
    {
        return [
            'id' => $faq->id,
            'uuid' => $faq->uuid,
            'question' => $faq->question,
            'answer' => $faq->answer,
            'sort_order' => $faq->sort_order,
            'is_published' => $faq->is_published,
            'created_by' => $faq->created_by,
            'created_at' => $faq->created_at?->toIso8601String(),
            'updated_at' => $faq->updated_at?->toIso8601String(),
            'deleted_at' => $faq->deleted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function validatedPayload(Request $request, bool $isUpdate = false): array
    {
        $validated = $request->validate([
            'question' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:500'],
            'answer' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:50000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('is_published', $validated)) {
            $validated['is_published'] = (bool) $validated['is_published'];
        }

        if (array_key_exists('sort_order', $validated)) {
            $validated['sort_order'] = (int) $validated['sort_order'];
        }

        return $validated;
    }
}
