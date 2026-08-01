<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesDocumentMemberRoles;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendDocumentEmailRequest;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentVaultEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    use HandlesDocumentMemberRoles;

    public function __construct(
        protected DocumentService $documents,
        protected DocumentVaultEmailService $vaultEmail,
    ) {}

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
            'cabinet_id' => ['nullable', 'integer'],
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
            isset($validated['cabinet_id']) ? (int) $validated['cabinet_id'] : null,
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

    public function showContent(Request $request, int $id): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->documents->getFileContent($businessId, $request->user(), $id),
        ]);
    }

    public function updateContent(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string'],
        ]);

        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->documents->updateFileContent(
                $businessId,
                $request->user(),
                $id,
                $validated['content'],
            ),
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'folder_id' => ['nullable', 'integer'],
            'cabinet_id' => ['nullable', 'integer'],
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
                isset($validated['cabinet_id']) ? (int) $validated['cabinet_id'] : null,
            ),
        ], 201);
    }

    public function storeLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'url' => ['required', 'string', 'max:2000'],
            'folder_id' => ['nullable', 'integer'],
            'cabinet_id' => ['nullable', 'integer'],
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
                isset($validated['cabinet_id']) ? (int) $validated['cabinet_id'] : null,
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

    public function emailDocument(SendDocumentEmailRequest $request, int $id): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $to = trim((string) ($request->validated('to') ?? ''));

        if ($to === '') {
            return response()->json(['message' => 'Enter a recipient email address.'], 422);
        }

        $result = $this->vaultEmail->sendFile(
            $businessId,
            $request->user(),
            $id,
            $to,
            $request->validated('message'),
        );

        return response()->json($result);
    }
}
