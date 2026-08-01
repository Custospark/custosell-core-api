<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesDocumentMemberRoles;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Documents\DocumentAccessService;
use App\Services\Documents\DocumentActivityService;
use App\Services\Documents\DocumentCabinetService;
use App\Services\Documents\DocumentVaultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentCabinetController extends Controller
{
    use HandlesDocumentMemberRoles;

    public function __construct(
        protected DocumentCabinetService $cabinets,
        protected DocumentAccessService $access,
        protected DocumentVaultService $vault,
        protected DocumentActivityService $activity,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $businessId = (int) $request->user()->business_id;

        return response()->json($this->cabinets->listPaginated(
            $businessId,
            $request->user(),
            $validated['q'] ?? null,
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 50),
        ));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->cabinets->show($businessId, $request->user(), $id),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['required', 'string'],
            'cover_color' => ['nullable', 'string', 'max:7'],
            'member_user_ids' => ['array'],
            'member_user_ids.*' => ['integer'],
            'member_roles' => ['array'],
        ]);

        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->cabinets->create(
                $businessId,
                $request->user(),
                $validated['name'],
                $validated['description'] ?? null,
                $validated['visibility'],
                array_map('intval', $validated['member_user_ids'] ?? []),
                $this->parseMemberRoles($request),
                $validated['cover_color'] ?? null,
            ),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', 'string'],
            'cover_color' => ['nullable', 'string', 'max:7'],
            'background_type' => ['nullable', 'string', 'max:32'],
            'background_value' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'member_user_ids' => ['array'],
            'member_user_ids.*' => ['integer'],
            'member_roles' => ['array'],
        ]);

        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->cabinets->update(
                $businessId,
                $request->user(),
                $id,
                $validated['name'] ?? null,
                array_key_exists('description', $validated) ? $validated['description'] : null,
                $validated['visibility'] ?? null,
                array_key_exists('member_user_ids', $validated) ? array_map('intval', $validated['member_user_ids']) : null,
                array_key_exists('member_roles', $validated) ? $this->parseMemberRoles($request) : null,
                array_key_exists('cover_color', $validated) ? ($validated['cover_color'] ?? '') : null,
                array_key_exists('background_type', $validated) ? ($validated['background_type'] ?? '') : null,
                array_key_exists('background_value', $validated) ? ($validated['background_value'] ?? '') : null,
                isset($validated['sort_order']) ? (int) $validated['sort_order'] : null,
            ),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $this->cabinets->destroy($businessId, $request->user(), $id);

        return response()->json(['message' => 'Cabinet deleted.']);
    }

    public function vaultAppearance(Request $request): JsonResponse
    {
        $business = Business::query()->findOrFail((int) $request->user()->business_id);

        return response()->json([
            'data' => $this->vault->getAppearance($business),
        ]);
    }

    public function updateVaultAppearance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cover_color' => ['nullable', 'string', 'max:7'],
            'background_type' => ['nullable', 'string', 'max:16'],
            'background_value' => ['nullable', 'string', 'max:500'],
        ]);

        $business = Business::query()->findOrFail((int) $request->user()->business_id);

        return response()->json([
            'data' => $this->vault->updateAppearance($business, $request->user(), $validated),
        ]);
    }

    public function accessibleMembers(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->access->listAccessibleMembers($businessId),
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cabinet_id' => ['required', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $businessId = (int) $request->user()->business_id;
        $cabinetId = (int) $validated['cabinet_id'];
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 30);

        $cabinet = $this->cabinets->findCabinet($businessId, $cabinetId);
        $this->access->assertCanViewCabinet($request->user(), $cabinet);

        return response()->json($this->activity->listRecent($businessId, $cabinetId, $page, $perPage));
    }
}
