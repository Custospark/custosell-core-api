<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BoardWallPostResource;
use App\Models\BoardWallPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class WallOfFameController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $posts = BoardWallPost::query()
            ->where('business_id', $request->user()->business_id)
            ->active()
            ->with(['creator', 'staff'])
            ->orderBy('pinned', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return BoardWallPostResource::collection($posts);
    }

    public function store(Request $request): BoardWallPostResource
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:quote,shoutout,performer,milestone'],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:5000'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'board_id' => ['nullable', 'integer', 'exists:pipeline_boards,id'],
            'expires_at' => ['nullable', 'date'],
            'pinned' => ['nullable', 'boolean'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $photo = null;
        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('wall-of-fame', 'public');
            $photo = '/storage/' . $path;
        }

        $post = BoardWallPost::create([
            'business_id' => $request->user()->business_id,
            'created_by' => $request->user()->id,
            'type' => $validated['type'],
            'title' => $validated['title'] ?? null,
            'content' => $validated['content'],
            'photo' => $photo,
            'staff_id' => $validated['staff_id'] ?? null,
            'author_name' => $validated['author_name'] ?? null,
            'board_id' => $validated['board_id'] ?? null,
            'expires_at' => $validated['expires_at'] ?? now()->addDays(7),
            'pinned' => $validated['pinned'] ?? false,
        ]);

        $post->load(['creator', 'staff']);

        return new BoardWallPostResource($post);
    }

    public function update(Request $request, BoardWallPost $wallPost): BoardWallPostResource
    {
        $this->authorizeAccess($request->user(), $wallPost);

        $validated = $request->validate([
            'type' => ['sometimes', 'string', 'in:quote,shoutout,performer,milestone'],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['sometimes', 'string', 'max:5000'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'expires_at' => ['nullable', 'date'],
            'pinned' => ['sometimes', 'boolean'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $data = $validated;

        if ($request->hasFile('photo')) {
            if ($wallPost->photo) {
                $old = ltrim(str_replace('/storage/', '', $wallPost->photo), '/');
                Storage::disk('public')->delete($old);
            }
            $path = $request->file('photo')->store('wall-of-fame', 'public');
            $data['photo'] = '/storage/' . $path;
        }

        $wallPost->update($data);
        $wallPost->load(['creator', 'staff']);

        return new BoardWallPostResource($wallPost);
    }

    public function destroy(Request $request, BoardWallPost $wallPost): JsonResponse
    {
        $this->authorizeAccess($request->user(), $wallPost);

        if ($wallPost->photo) {
            $old = ltrim(str_replace('/storage/', '', $wallPost->photo), '/');
            Storage::disk('public')->delete($old);
        }

        $wallPost->delete();

        return response()->json(['message' => 'Post removed']);
    }

    private function authorizeAccess($user, BoardWallPost $post): void
    {
        if ($post->business_id !== $user->business_id) {
            abort(403, 'Unauthorized');
        }
    }
}
