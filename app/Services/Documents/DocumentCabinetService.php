<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentCabinet;
use App\Models\DocumentFolder;
use App\Models\User;
use Illuminate\Support\Str;

class DocumentCabinetService
{
    public function __construct(
        protected DocumentAccessService $access,
        protected DocumentActivityService $activity,
    ) {}

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    public function listPaginated(int $businessId, User $user, ?string $query = null, int $page = 1, int $perPage = 50): array
    {
        $perPage = min(max($perPage, 1), 200);
        $page = max($page, 1);

        $builder = DocumentCabinet::query()
            ->where('business_id', $businessId)
            ->with(['creator:id,name,avatar', 'members:id,name,avatar'])
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($query !== null && trim($query) !== '') {
            $term = '%'.trim($query).'%';
            $builder->where(function ($q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        $paginator = $builder->paginate($perPage, ['*'], 'page', $page);

        $data = $paginator->getCollection()
            ->filter(fn (DocumentCabinet $cabinet) => $this->access->canViewCabinet($user, $cabinet))
            ->map(fn (DocumentCabinet $cabinet) => $this->serializeCabinet($cabinet, $user))
            ->values()
            ->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => count($data),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function show(int $businessId, User $user, int $cabinetId): array
    {
        $cabinet = $this->findCabinet($businessId, $cabinetId);
        $this->access->assertCanViewCabinet($user, $cabinet);

        return $this->serializeCabinet($this->reloadCabinet($cabinet), $user);
    }

    /** @param  list<int>  $memberUserIds
     * @param  array<int, string>  $memberRoles
     * @return array<string, mixed>
     */
    public function create(
        int $businessId,
        User $user,
        string $name,
        ?string $description,
        string $visibility,
        array $memberUserIds = [],
        array $memberRoles = [],
        ?string $coverColor = null,
    ): array {
        $this->access->assertHasDocumentsModule($user);
        $this->access->assertValidVisibility($visibility, $memberUserIds, false);

        $cabinet = DocumentCabinet::create([
            'business_id' => $businessId,
            'name' => trim($name),
            'description' => $description,
            'visibility' => $visibility,
            'cover_color' => $coverColor,
            'created_by' => $user->id,
        ]);

        $this->access->syncCabinetMembers($cabinet, $businessId, $memberUserIds, $memberRoles);

        $this->activity->record(
            $businessId,
            $user,
            'cabinet_created',
            'cabinet',
            $cabinet->id,
            $cabinet->name,
            null,
            $cabinet->id,
        );

        return $this->serializeCabinet($this->reloadCabinet($cabinet), $user);
    }

    /** @param  list<int>|null  $memberUserIds
     * @param  array<int, string>|null  $memberRoles
     * @return array<string, mixed>
     */
    public function update(
        int $businessId,
        User $user,
        int $cabinetId,
        ?string $name = null,
        ?string $description = null,
        ?string $visibility = null,
        ?array $memberUserIds = null,
        ?array $memberRoles = null,
        ?string $coverColor = null,
        ?string $backgroundType = null,
        ?string $backgroundValue = null,
        ?int $sortOrder = null,
    ): array {
        $cabinet = $this->findCabinet($businessId, $cabinetId);
        $this->access->assertCanManageCabinet($user, $cabinet);

        if ($name !== null) {
            $cabinet->name = trim($name);
        }
        if ($description !== null) {
            $cabinet->description = $description;
        }
        if ($sortOrder !== null) {
            $cabinet->sort_order = $sortOrder;
        }
        if ($coverColor !== null) {
            $cabinet->cover_color = $coverColor === '' ? null : $coverColor;
        }
        if ($backgroundType !== null) {
            $cabinet->background_type = $backgroundType === '' ? null : $backgroundType;
        }
        if ($backgroundValue !== null) {
            $cabinet->background_value = $backgroundValue === '' ? null : $backgroundValue;
        }

        if ($visibility !== null) {
            $this->access->assertValidVisibility(
                $visibility,
                $memberUserIds ?? $cabinet->members()->pluck('users.id')->map(fn ($id) => (int) $id)->all(),
                false,
            );
            $cabinet->visibility = $visibility;
        }

        $cabinet->save();

        if ($memberUserIds !== null) {
            $this->access->syncCabinetMembers($cabinet, $businessId, $memberUserIds, $memberRoles ?? []);
        } elseif ($visibility !== null && $visibility !== 'selected_staff') {
            $this->access->syncCabinetMembers($cabinet, $businessId, [], []);
        }

        return $this->serializeCabinet($this->reloadCabinet($cabinet), $user);
    }

    public function destroy(int $businessId, User $user, int $cabinetId): void
    {
        $cabinet = $this->findCabinet($businessId, $cabinetId);
        $this->access->assertCanManageCabinet($user, $cabinet);

        $hasFolders = DocumentFolder::query()->where('cabinet_id', $cabinet->id)->exists();
        $hasDocuments = Document::query()->where('cabinet_id', $cabinet->id)->exists();

        if ($hasFolders || $hasDocuments) {
            abort(422, 'Remove all folders and files from this cabinet before deleting it.');
        }

        $name = $cabinet->name;
        $cabinetId = $cabinet->id;
        $cabinet->memberLinks()->delete();
        $cabinet->delete();

        $this->activity->record($businessId, $user, 'cabinet_deleted', 'cabinet', null, $name, null, $cabinetId);
    }

    public function seedDefaultCabinets(int $businessId, ?int $ownerId = null): void
    {
        $starters = [
            [
                'name' => 'General',
                'description' => 'Shared company files and everyday documents',
                'cover_color' => '#6366f1',
                'background_type' => 'gallery',
                'background_value' => 'https://picsum.photos/id/10/1200/800',
                'sort_order' => 0,
            ],
            [
                'name' => 'HR',
                'description' => 'People policies, contracts, and HR records',
                'cover_color' => '#8b5cf6',
                'background_type' => 'gallery',
                'background_value' => 'https://picsum.photos/id/15/1200/800',
                'sort_order' => 1,
            ],
            [
                'name' => 'Finance',
                'description' => 'Invoices, budgets, tax records, and accounting files',
                'cover_color' => '#059669',
                'background_type' => 'gallery',
                'background_value' => 'https://picsum.photos/id/26/1200/800',
                'sort_order' => 2,
            ],
            [
                'name' => 'Legal & Compliance',
                'description' => 'Agreements, licenses, and regulatory documents',
                'cover_color' => '#dc2626',
                'background_type' => 'gallery',
                'background_value' => 'https://picsum.photos/id/28/1200/800',
                'sort_order' => 3,
            ],
            [
                'name' => 'Sales & Marketing',
                'description' => 'Proposals, campaigns, brand assets, and collateral',
                'cover_color' => '#ea580c',
                'background_type' => 'gallery',
                'background_value' => 'https://picsum.photos/id/36/1200/800',
                'sort_order' => 4,
            ],
            [
                'name' => 'Operations',
                'description' => 'SOPs, vendor files, and day-to-day operations',
                'cover_color' => '#0284c7',
                'background_type' => 'gallery',
                'background_value' => 'https://picsum.photos/id/40/1200/800',
                'sort_order' => 5,
            ],
        ];

        foreach ($starters as $starter) {
            $exists = DocumentCabinet::withTrashed()
                ->where('business_id', $businessId)
                ->where('name', $starter['name'])
                ->exists();

            if ($exists) {
                continue;
            }

            DocumentCabinet::query()->create([
                'business_id' => $businessId,
                'name' => $starter['name'],
                'description' => $starter['description'],
                'visibility' => 'all_staff',
                'cover_color' => $starter['cover_color'],
                'background_type' => $starter['background_type'],
                'background_value' => $starter['background_value'],
                'sort_order' => $starter['sort_order'],
                'created_by' => $ownerId,
            ]);
        }
    }

    /** @deprecated Use seedDefaultCabinets() */
    public function ensureGeneralCabinet(int $businessId, ?int $ownerId = null): DocumentCabinet
    {
        $this->seedDefaultCabinets($businessId, $ownerId);

        return DocumentCabinet::query()
            ->where('business_id', $businessId)
            ->where('name', 'General')
            ->firstOrFail();
    }

    public function findCabinet(int $businessId, int $cabinetId): DocumentCabinet
    {
        return DocumentCabinet::query()
            ->where('business_id', $businessId)
            ->whereKey($cabinetId)
            ->firstOrFail();
    }

    protected function reloadCabinet(DocumentCabinet $cabinet): DocumentCabinet
    {
        return $cabinet->fresh([
            'creator:id,name,avatar',
            'members:id,name,avatar',
        ]) ?? $cabinet;
    }

    /** @return array<string, mixed> */
    public function serializeCabinet(DocumentCabinet $cabinet, User $user): array
    {
        $flags = $this->access->cabinetPermissionFlags($user, $cabinet);

        $folderCount = DocumentFolder::query()->where('cabinet_id', $cabinet->id)->count();
        $documentCount = Document::query()->where('cabinet_id', $cabinet->id)->count();

        return [
            'id' => $cabinet->id,
            'name' => $cabinet->name,
            'description' => $cabinet->description,
            'visibility' => $cabinet->visibility,
            'cover_color' => $cabinet->cover_color,
            'background_type' => $cabinet->background_type,
            'background_value' => $cabinet->background_value,
            'sort_order' => $cabinet->sort_order,
            'folder_count' => $folderCount,
            'document_count' => $documentCount,
            'created_at' => $cabinet->created_at?->toISOString(),
            'updated_at' => $cabinet->updated_at?->toISOString(),
            'creator' => $cabinet->creator ? [
                'id' => $cabinet->creator->id,
                'name' => $cabinet->creator->name,
                'avatar' => $cabinet->creator->avatar,
            ] : null,
            'members' => $cabinet->members->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name,
                'avatar' => $member->avatar,
                'role' => $member->pivot->role ?? 'viewer',
            ])->values()->all(),
            ...$flags,
        ];
    }
}
