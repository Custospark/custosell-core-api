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
        $cabinet->memberLinks()->delete();
        $cabinet->delete();

        $this->activity->record($businessId, $user, 'cabinet_deleted', 'cabinet', null, $name, null);
    }

    public function ensureGeneralCabinet(int $businessId, ?int $ownerId = null): DocumentCabinet
    {
        $existing = DocumentCabinet::query()
            ->where('business_id', $businessId)
            ->where('name', 'General')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DocumentCabinet::create([
            'business_id' => $businessId,
            'name' => 'General',
            'description' => 'Default document cabinet',
            'visibility' => 'all_staff',
            'sort_order' => 0,
            'created_by' => $ownerId,
        ]);
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
