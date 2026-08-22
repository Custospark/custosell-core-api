<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesDocumentMemberRoles;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendDocumentEmailRequest;
use App\Services\Documents\DocumentFolderService;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentVaultEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentFolderController extends Controller
{
    use HandlesDocumentMemberRoles;

    public function __construct(
        protected DocumentFolderService $folders,
        protected DocumentService $documents,
        protected DocumentVaultEmailService $vaultEmail,
    ) {}

    public function tree(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cabinet_id' => ['nullable', 'integer'],
        ]);

        $businessId = (int) $request->user()->business_id;
        $cabinetId = isset($validated['cabinet_id']) ? (int) $validated['cabinet_id'] : null;

        return response()->json([
            'data' => $this->folders->tree($businessId, $request->user(), $cabinetId),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->folders->show($businessId, $request->user(), $id),
        ]);
    }

    public function children(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cabinet_id' => ['required', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $businessId = (int) $request->user()->business_id;
        $perPage = (int) ($validated['per_page'] ?? config('documents.folder_page_size', 100));

        $result = $this->folders->listChildren(
            $businessId,
            $request->user(),
            (int) $validated['cabinet_id'],
            isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
            (int) ($validated['page'] ?? 1),
            $perPage,
        );

        return response()->json($result);
    }

    public function contents(Request $request, int $id): JsonResponse
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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['required', 'string'],
            'parent_id' => ['nullable', 'integer'],
            'cabinet_id' => ['nullable', 'integer'],
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
                isset($validated['cabinet_id']) ? (int) $validated['cabinet_id'] : null,
            ),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', 'string'],
            'parent_id' => ['nullable', 'integer'],
            'cabinet_id' => ['nullable', 'integer'],
            'member_user_ids' => ['array'],
            'member_user_ids.*' => ['integer'],
            'member_roles' => ['array'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'cover_color' => ['nullable', 'string', 'max:7'],
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
                array_key_exists('cover_color', $validated) ? ($validated['cover_color'] ?? '') : null,
                array_key_exists('cabinet_id', $validated) && $validated['cabinet_id'] !== null ? (int) $validated['cabinet_id'] : null,
            ),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $this->folders->destroy($businessId, $request->user(), $id);

        return response()->json(['message' => 'Folder deleted.']);
    }

    public function export(Request $request, int $id): StreamedResponse
    {
        $businessId = (int) $request->user()->business_id;

        return $this->folders->exportFolder($businessId, $request->user(), $id);
    }

    public function email(SendDocumentEmailRequest $request, int $id): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $to = trim((string) ($request->validated('to') ?? ''));

        if ($to === '') {
            return response()->json(['message' => 'Enter a recipient email address.'], 422);
        }

        $result = $this->vaultEmail->sendFolder(
            $businessId,
            $request->user(),
            $id,
            $to,
            $request->validated('message'),
        );

        return response()->json($result);
    }
}
