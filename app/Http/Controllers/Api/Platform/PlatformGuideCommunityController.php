<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\GuideCommunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformGuideCommunityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_published' => 'nullable|boolean',
            'include_trash' => 'nullable|boolean',
        ]);

        $query = GuideCommunity::query()->orderBy('sort_order')->orderBy('id');

        if (array_key_exists('is_published', $validated) && $validated['is_published'] !== null) {
            $query->where('is_published', (bool) $validated['is_published']);
        }

        if (! empty($validated['include_trash'])) {
            $query->withTrashed();
        }

        return response()->json([
            'data' => $query->get()->map(fn (GuideCommunity $community) => $this->serialize($community))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedPayload($request);

        $community = new GuideCommunity($data);
        $community->created_by = $request->user()?->id;
        $community->save();

        return response()->json([
            'data' => $this->serialize($community->fresh()),
            'message' => 'Community created successfully.',
        ], 201);
    }

    public function update(Request $request, GuideCommunity $guideCommunity): JsonResponse
    {
        $guideCommunity->fill($this->validatedPayload($request, isUpdate: true));
        $guideCommunity->save();

        return response()->json([
            'data' => $this->serialize($guideCommunity->fresh()),
            'message' => 'Community updated successfully.',
        ]);
    }

    public function destroy(GuideCommunity $guideCommunity): JsonResponse
    {
        $guideCommunity->delete();

        return response()->json([
            'data' => null,
            'message' => 'Community archived successfully.',
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(GuideCommunity $community): array
    {
        return [
            'id' => $community->id,
            'uuid' => $community->uuid,
            'name' => $community->name,
            'description' => $community->description,
            'platform' => $community->platform,
            'url' => $community->url,
            'icon' => $community->icon,
            'sort_order' => $community->sort_order,
            'is_published' => $community->is_published,
            'created_by' => $community->created_by,
            'created_at' => $community->created_at?->toISOString(),
            'updated_at' => $community->updated_at?->toISOString(),
            'deleted_at' => $community->deleted_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function validatedPayload(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'platform' => ['required', 'string', 'max:32'],
            'url' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:2048', 'url'],
            'icon' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ]);
    }
}