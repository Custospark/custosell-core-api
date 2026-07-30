<?php

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Pipeline\PipelineBoardResourceService;
use App\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineResourceController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
        protected PipelineBoardResourceService $boardResources,
    ) {}

    public function boardResourcesSummary(Request $request, int $boardId): JsonResponse
    {
        $summary = $this->boardResources->resourcesSummary(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $summary]);
    }

    public function boardResources(Request $request, int $boardId): JsonResponse
    {
        $items = $this->boardResources->listResources(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $items]);
    }

    public function boardResourceMembers(Request $request, int $boardId): JsonResponse
    {
        $members = $this->boardResources->listAccessibleMembers(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $members]);
    }

    public function storeBoardLinkResource(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048'],
            'description' => ['nullable', 'string', 'max:5000'],
            'group_name' => ['nullable', 'string', 'max:100'],
            'visibility' => ['required', 'in:board,team,members,owner_only'],
            'member_user_ids' => ['nullable', 'array'],
            'member_user_ids.*' => ['integer'],
        ]);

        $resource = $this->boardResources->createLinkResource(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['title'],
            $validated['url'],
            $validated['visibility'],
            $validated['description'] ?? null,
            $validated['group_name'] ?? null,
            $validated['member_user_ids'] ?? [],
        );

        return response()->json(['data' => $resource], 201);
    }

    public function uploadBoardResource(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xlsx,txt,csv'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'group_name' => ['nullable', 'string', 'max:100'],
            'visibility' => ['required', 'in:board,team,members,owner_only'],
            'member_user_ids' => ['nullable', 'array'],
            'member_user_ids.*' => ['integer'],
        ]);

        $resource = $this->boardResources->uploadResource(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $request->file('file'),
            $validated['visibility'],
            $validated['title'] ?? null,
            $validated['description'] ?? null,
            $validated['group_name'] ?? null,
            $validated['member_user_ids'] ?? [],
        );

        return response()->json(['data' => $resource], 201);
    }

    public function updateBoardResource(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'group_name' => ['nullable', 'string', 'max:100'],
            'visibility' => ['sometimes', 'in:board,team,members,owner_only'],
            'url' => ['sometimes', 'string', 'max:2048'],
            'member_user_ids' => ['nullable', 'array'],
            'member_user_ids.*' => ['integer'],
        ]);

        $resource = $this->boardResources->updateResource(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated['title'] ?? null,
            array_key_exists('description', $validated) ? $validated['description'] : null,
            $validated['visibility'] ?? null,
            $validated['url'] ?? null,
            array_key_exists('group_name', $validated) ? ($validated['group_name'] ?? null) : null,
            $validated['member_user_ids'] ?? [],
            array_key_exists('group_name', $validated),
        );

        return response()->json(['data' => $resource]);
    }

    public function destroyBoardResource(Request $request, int $id): JsonResponse
    {
        $this->boardResources->deleteResource(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['message' => 'Resource deleted']);
    }

    public function recordBoardResourceView(Request $request, int $id): JsonResponse
    {
        $resource = $this->boardResources->recordView(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['data' => $resource]);
    }

    public function recordBoardResourceDownload(Request $request, int $id): JsonResponse
    {
        $resource = $this->boardResources->recordDownload(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['data' => $resource]);
    }
}
