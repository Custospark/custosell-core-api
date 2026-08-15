<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuickNoteRequest;
use App\Http\Resources\QuickNoteCollection;
use App\Http\Resources\QuickNoteResource;
use App\Services\Contracts\QuickNoteServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuickNoteController extends Controller
{
    public function __construct(
        protected QuickNoteServiceInterface $quickNoteService,
    ) {}

    public function index(Request $request): QuickNoteCollection
    {
        $user = $request->user();
        abort_unless($this->quickNoteService->canUseFeature($user), 403, 'Quick notes are not available on this account.');

        return new QuickNoteCollection(
            $this->quickNoteService->visibleNotes($user->business_id, $user->id),
        );
    }

    public function show(Request $request, int $id): QuickNoteResource
    {
        $user = $request->user();
        abort_unless($this->quickNoteService->canUseFeature($user), 403, 'Quick notes are not available on this account.');

        $note = $this->quickNoteService->getOwned($user->business_id, $user->id, $id);
        if (! $note) {
            abort(404, 'Quick note not found');
        }

        return new QuickNoteResource($note);
    }

    public function store(QuickNoteRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->quickNoteService->canUseFeature($user), 403, 'Quick notes are not available on this account.');

        $data = $request->validated();
        if (! $this->quickNoteService->canShare($user)) {
            $data['is_shared'] = false;
        }

        $note = $this->quickNoteService->create($user->business_id, $user->id, $data);

        return response()->json(['data' => new QuickNoteResource($note)], 201);
    }

    public function update(QuickNoteRequest $request, int $id): QuickNoteResource
    {
        $user = $request->user();
        abort_unless($this->quickNoteService->canUseFeature($user), 403, 'Quick notes are not available on this account.');

        $data = $request->validated();
        if (! $this->quickNoteService->canShare($user)) {
            $data['is_shared'] = false;
        }

        $note = $this->quickNoteService->update($user->business_id, $user->id, $id, $data);

        return new QuickNoteResource($note);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->quickNoteService->canUseFeature($user), 403, 'Quick notes are not available on this account.');

        $this->quickNoteService->delete($user->business_id, $user->id, $id);

        return response()->json(null, 204);
    }

    public function reorder(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->quickNoteService->canUseFeature($user), 403, 'Quick notes are not available on this account.');

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $this->quickNoteService->reorder($user->business_id, $user->id, $data['order']);

        return response()->json(['ok' => true]);
    }

    /** Save the user's notes-page background preference (gallery image or solid color). */
    public function saveBackground(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->quickNoteService->canUseFeature($user), 403, 'Quick notes are not available on this account.');

        $data = $request->validate([
            'type' => ['required', 'string', 'in:gallery,color'],
            'value' => ['nullable', 'string', 'max:500'],
        ]);

        $user->setNotesBackground($data['type'], $data['value'] ?? null);
        $user->save();

        return response()->json(['data' => ['preferences' => $user->preferences]]);
    }
}
