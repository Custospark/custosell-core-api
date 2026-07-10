<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Documents\DocumentAccessService;
use App\Services\Documents\DocumentFolderService;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __construct(
        protected DocumentAccessService $access,
        protected DocumentFolderService $folders,
        protected DocumentService $documents,
        protected DocumentTagService $tags,
    ) {}

    public function accessibleMembers(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->access->listAccessibleMembers($businessId),
        ]);
    }

    public function folderTree(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->folders->tree($businessId, $request->user()),
        ]);
    }

    public function showFolder(Request $request, int $id): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->folders->show($businessId, $request->user(), $id),
        ]);
    }

    public function folderChildren(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $businessId = (int) $request->user()->business_id;
        $perPage = (int) ($validated['per_page'] ?? config('documents.folder_page_size', 100));

        $result = $this->folders->listChildren(
            $businessId,
            $request->user(),
            isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
            (int) ($validated['page'] ?? 1),
            $perPage,
        );

        return response()->json($result);
    }

    public function folderContents(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $businessId = (int) $request->user()->business_id;
        $perPage = (int) ($validated['per_page'] ?? config('documents.per_page', 50));

        return response()->json([
            'data' => $this->folders->contents(
                $businessId,
                $request->user(),
                $id,
                $this->documents,
                (int) ($validated['page'] ?? 1),
                $perPage,
            ),
        ]);
    }

    public function storeFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['required', 'string'],
            'parent_id' => ['nullable', 'integer'],
            'member_user_ids' => ['array'],
            'member_user_ids.*' => ['integer'],
            'member_roles' => ['array'],
        ]);

        $businessId = (int) $request->user()->business_id;
        $memberRoles = $this->parseMemberRoles($request);

        return response()->json([
            'data' => $this->folders->create(
                $businessId,
                $request->user(),
                $validated['name'],
                $validated['description'] ?? null,
                $validated['visibility'],
                isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
                array_map('intval', $validated['member_user_ids'] ?? []),
                $memberRoles,
            ),
        ], 201);
    }

    public function updateFolder(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', 'string'],
            'parent_id' => ['nullable', 'integer'],
            'member_user_ids' => ['array'],
            'member_user_ids.*' => ['integer'],
            'member_roles' => ['array'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->folders->update(
                $businessId,
                $request->user(),
                $id,
                $validated['name'] ?? null,
                array_key_exists('description', $validated) ? $validated['description'] : null,
                $validated['visibility'] ?? null,
                array_key_exists('parent_id', $validated) ? ($validated['parent_id'] !== null ? (int) $validated['parent_id'] : null) : null,
                array_key_exists('member_user_ids', $validated) ? array_map('intval', $validated['member_user_ids']) : null,
                array_key_exists('member_roles', $validated) ? $this->parseMemberRoles($request) : null,
                isset($validated['sort_order']) ? (int) $validated['sort_order'] : null,
            ),
        ]);
    }

    public function destroyFolder(Request $request, int $id): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $this->folders->destroy($businessId, $request->user(), $id);

        return response()->json(['message' => 'Folder deleted.']);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'folder_id' => ['nullable', 'integer'],
            'tag' => ['nullable', 'string', 'max:50'],
            'customer_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string'],
            'uploaded_by' => ['nullable', 'integer'],
            'root_only' => ['nullable'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $businessId = (int) $request->user()->business_id;
        $perPage = (int) ($validated['per_page'] ?? config('documents.per_page', 50));

        $result = $this->documents->listPaginated(
            $businessId,
            $request->user(),
            $validated['q'] ?? null,
            isset($validated['folder_id']) ? (int) $validated['folder_id'] : null,
            $validated['tag'] ?? null,
            isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
            isset($validated['project_id']) ? (int) $validated['project_id'] : null,
            $validated['type'] ?? null,
            isset($validated['uploaded_by']) ? (int) $validated['uploaded_by'] : null,
            $request->boolean('root_only'),
            (int) ($validated['page'] ?? 1),
            $perPage,
        );

        return response()->json($result);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->documents->show($businessId, $request->user(), $id),
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'folder_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['nullable', 'string'],
            'member_user_ids' => ['array'],
            'member_user_ids.*' => ['integer'],
            'member_roles' => ['array'],
            'customer_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'tags' => ['array'],
            'tags.*' => ['string', 'max:50'],
        ]);

        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->documents->upload(
                $businessId,
                $request->user(),
                $request->file('file'),
                isset($validated['folder_id']) ? (int) $validated['folder_id'] : null,
                $validated['title'] ?? null,
                $validated['visibility'] ?? 'inherit',
                $validated['description'] ?? null,
                array_map('intval', $validated['member_user_ids'] ?? []),
                $this->parseMemberRoles($request),
                isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
                isset($validated['project_id']) ? (int) $validated['project_id'] : null,
                $validated['tags'] ?? [],
            ),
        ], 201);
    }

    public function storeLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'url' => ['required', 'string', 'max:2000'],
            'folder_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['nullable', 'string'],
            'member_user_ids' => ['array'],
            'member_user_ids.*' => ['integer'],
            'member_roles' => ['array'],
            'customer_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'tags' => ['array'],
            'tags.*' => ['string', 'max:50'],
        ]);

        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->documents->createLink(
                $businessId,
                $request->user(),
                $validated['title'],
                $validated['url'],
                isset($validated['folder_id']) ? (int) $validated['folder_id'] : null,
                $validated['visibility'] ?? 'inherit',
                $validated['description'] ?? null,
                array_map('intval', $validated['member_user_ids'] ?? []),
                $this->parseMemberRoles($request),
                isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
                isset($validated['project_id']) ? (int) $validated['project_id'] : null,
                $validated['tags'] ?? [],
            ),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', 'string'],
            'folder_id' => ['nullable', 'integer'],
            'member_user_ids' => ['array'],
            'member_user_ids.*' => ['integer'],
            'member_roles' => ['array'],
            'customer_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'clear_customer' => ['boolean'],
            'clear_project' => ['boolean'],
            'tags' => ['array'],
            'tags.*' => ['string', 'max:50'],
            'url' => ['nullable', 'string', 'max:2000'],
        ]);

        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->documents->update(
                $businessId,
                $request->user(),
                $id,
                $validated['title'] ?? null,
                array_key_exists('description', $validated) ? $validated['description'] : null,
                $validated['visibility'] ?? null,
                array_key_exists('folder_id', $validated) ? ($validated['folder_id'] !== null ? (int) $validated['folder_id'] : null) : null,
                array_key_exists('member_user_ids', $validated) ? array_map('intval', $validated['member_user_ids']) : null,
                array_key_exists('member_roles', $validated) ? $this->parseMemberRoles($request) : null,
                array_key_exists('customer_id', $validated) ? ($validated['customer_id'] !== null ? (int) $validated['customer_id'] : null) : null,
                array_key_exists('project_id', $validated) ? ($validated['project_id'] !== null ? (int) $validated['project_id'] : null) : null,
                $validated['tags'] ?? null,
                $validated['url'] ?? null,
                (bool) ($validated['clear_customer'] ?? false),
                (bool) ($validated['clear_project'] ?? false),
            ),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $this->documents->destroy($businessId, $request->user(), $id);

        return response()->json(['message' => 'Document deleted.']);
    }

    public function recordView(Request $request, int $id): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->documents->recordView($businessId, $request->user(), $id),
        ]);
    }

    public function recordDownload(Request $request, int $id): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->documents->recordDownload($businessId, $request->user(), $id),
        ]);
    }

    public function tags(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:50'],
        ]);

        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->tags->list($businessId, $validated['q'] ?? null),
        ]);
    }

    public function storeTag(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
        ]);

        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->tags->create($businessId, $validated['name']),
        ], 201);
    }

    /** @return array<int, string> */
    protected function parseMemberRoles(Request $request): array
    {
        $roles = $request->input('member_roles', []);
        if (! is_array($roles)) {
            return [];
        }

        $parsed = [];
        foreach ($roles as $userId => $role) {
            $parsed[(int) $userId] = is_string($role) ? $role : 'viewer';
        }

        return $parsed;
    }
}
