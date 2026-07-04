<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJournalEntryRequest;
use App\Http\Resources\JournalEntryCollection;
use App\Http\Resources\JournalEntryLineResource;
use App\Http\Resources\JournalEntryResource;
use App\Services\JournalEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
    ) {}

    public function index(Request $request): JournalEntryCollection
    {
        $businessId = $request->user()->business_id;
        $filters = $request->only(['period_id', 'date_from', 'date_to', 'reference_type', 'locked']);
        return new JournalEntryCollection(
            $this->journalEntryService->getAll($businessId, $filters)
        );
    }

    public function show(int $id): JournalEntryResource
    {
        return new JournalEntryResource(
            $this->journalEntryService->getById($id)
        );
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $userId = $request->user()->id;
        $data = $request->validated();

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('journal-attachments', 'public');
        }

        $entry = $this->journalEntryService->createDraft($businessId, $userId, $data);
        return response()->json(new JournalEntryResource($entry), 201);
    }

    public function post(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;
        $entry = $this->journalEntryService->post($id, $userId);
        return response()->json(new JournalEntryResource($entry));
    }

    public function reverse(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;
        $entry = $this->journalEntryService->createReversingEntry($id, $userId);
        return response()->json(new JournalEntryResource($entry), 201);
    }

    public function lines(int $id): JsonResponse
    {
        return response()->json([
            'data' => JournalEntryLineResource::collection(
                $this->journalEntryService->getLines($id)
            ),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->journalEntryService->deleteEntry($id);
        return response()->json(null, 204);
    }
}
