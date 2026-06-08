<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\GuideTutorialResource;
use App\Models\GuideTutorial;
use App\Services\Guide\VideoThumbnailResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlatformGuideTutorialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', Rule::in(GuideTutorial::allowedCategories())],
            'is_published' => 'nullable|boolean',
            'include_trash' => 'nullable|boolean',
        ]);

        $query = GuideTutorial::query()->orderBy('sort_order')->orderBy('id');

        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        if (array_key_exists('is_published', $validated) && $validated['is_published'] !== null) {
            $query->where('is_published', (bool) $validated['is_published']);
        }

        if (! empty($validated['include_trash'])) {
            $query->withTrashed();
        }

        $items = $query->get();

        return response()->json([
            'data' => $items
                ->map(fn (GuideTutorial $t) => (new GuideTutorialResource($t))->toArray($request))
                ->values(),
        ]);
    }

    public function previewThumbnail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'video_url' => ['required', 'string', 'max:2048', 'url'],
        ]);

        $resolved = VideoThumbnailResolver::resolve($data['video_url']);

        return response()->json([
            'data' => ['thumbnail_url' => $resolved],
            'message' => $resolved
                ? 'Preview thumbnail resolved for this video URL.'
                : 'No automatic preview is available for this host (try uploading an image).',
        ]);
    }

    public function uploadThumbnailForMaterial(Request $request, GuideTutorial $guideTutorial): JsonResponse
    {
        return $this->respondWithStoredThumbnail($request, $guideTutorial);
    }

    public function uploadThumbnailPending(Request $request): JsonResponse
    {
        return $this->respondWithStoredThumbnail($request, null);
    }

    private function respondWithStoredThumbnail(Request $request, ?GuideTutorial $tutorial): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            'previous_thumbnail_path' => ['nullable', 'string', 'max:512'],
        ]);

        $file = $request->file('photo');

        if ($tutorial !== null) {
            $disk = Storage::disk('public');
            $dir = 'guide-tutorial-thumbnails/'.$tutorial->id;
            $previousPath = $tutorial->thumbnail_path;

            if ($disk->exists($dir)) {
                $disk->deleteDirectory($dir);
            }

            if ($previousPath && $disk->exists($previousPath)) {
                $disk->delete($previousPath);
            }
        } else {
            $this->deletePendingThumbnailIfAllowed($request->string('previous_thumbnail_path')->toString());
        }

        $directory = $tutorial !== null
            ? 'guide-tutorial-thumbnails/'.$tutorial->id
            : 'guide-tutorial-thumbnails/pending/'.Str::ulid();

        $path = $file->store($directory, 'public');

        return response()->json([
            'data' => [
                'thumbnail_path' => $path,
                'thumbnail_url' => asset('storage/'.$path),
            ],
            'message' => 'Thumbnail uploaded successfully.',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedPayload($request);

        $tutorial = new GuideTutorial($data);
        $tutorial->created_by = $request->user()?->id;
        $tutorial->save();

        return response()->json([
            'data' => (new GuideTutorialResource($tutorial->fresh()))->toArray($request),
            'message' => 'Tutorial created successfully.',
        ], 201);
    }

    public function update(Request $request, GuideTutorial $guideTutorial): JsonResponse
    {
        $data = $this->validatedPayload($request, isUpdate: true);

        $previousPath = $guideTutorial->thumbnail_path;
        $guideTutorial->fill($data);

        if ($previousPath && $previousPath !== $guideTutorial->thumbnail_path) {
            Storage::disk('public')->delete($previousPath);
        }

        $guideTutorial->save();

        return response()->json([
            'data' => (new GuideTutorialResource($guideTutorial->fresh()))->toArray($request),
            'message' => 'Tutorial updated successfully.',
        ]);
    }

    public function destroy(GuideTutorial $guideTutorial): JsonResponse
    {
        $guideTutorial->delete();

        return response()->json([
            'data' => null,
            'message' => 'Tutorial archived successfully.',
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedPayload(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'title' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:50000'],
            'video_url' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:2048', 'url'],
            'thumbnail_path' => ['nullable', 'string', 'max:512'],
            'thumbnail_url' => ['nullable', 'string', 'max:2048', 'url'],
            'banner_image_url' => ['nullable', 'string', 'max:2048', 'url'],
            'category' => [$isUpdate ? 'sometimes' : 'required', 'string', Rule::in(GuideTutorial::allowedCategories())],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_published' => ['nullable', 'boolean'],
        ];

        $validated = $request->validate($rules);

        if (array_key_exists('is_published', $validated)) {
            $validated['is_published'] = (bool) $validated['is_published'];
        }

        if (array_key_exists('sort_order', $validated)) {
            $validated['sort_order'] = (int) $validated['sort_order'];
        }

        foreach (['thumbnail_path', 'thumbnail_url', 'banner_image_url'] as $key) {
            if (array_key_exists($key, $validated) && $validated[$key] === '') {
                $validated[$key] = null;
            }
        }

        return $validated;
    }

    private function deletePendingThumbnailIfAllowed(string $path): void
    {
        if ($path === '' || str_contains($path, '..')) {
            return;
        }

        $prefix = 'guide-tutorial-thumbnails/pending/';
        if (! str_starts_with($path, $prefix)) {
            return;
        }

        $disk = Storage::disk('public');
        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }
}
