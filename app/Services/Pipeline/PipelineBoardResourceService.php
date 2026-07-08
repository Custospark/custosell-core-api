<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardMember;
use App\Models\PipelineBoardResource;
use App\Models\PipelineBoardResourceMember;
use App\Models\Project;
use App\Models\User;
use App\Services\ModuleAccessService;
use App\Services\PipelineService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PipelineBoardResourceService
{
    public function __construct(
        protected PipelineService $pipeline,
        protected ModuleAccessService $moduleAccess,
    ) {}

    /** @return list<array{id: int, name: string, avatar: string|null}> */
    public function listAccessibleMembers(int $businessId, User $user, int $boardId): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);

        return User::query()
            ->whereIn('id', $this->boardAccessibleMemberIds($board))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'avatar'])
            ->map(fn (User $member) => [
                'id' => (int) $member->id,
                'name' => $member->name,
                'avatar' => $member->avatar,
            ])
            ->values()
            ->all();
    }

    /** @return array{resources_count: int} */
    public function resourcesSummary(int $businessId, User $user, int $boardId): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);
        $resources = $this->loadBoardResources($board);

        return [
            'resources_count' => $resources->filter(fn (PipelineBoardResource $item) => $this->canViewResource($user, $item, $board))->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listResources(int $businessId, User $user, int $boardId): array
    {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);
        $resources = $this->loadBoardResources($board)
            ->filter(fn (PipelineBoardResource $item) => $this->canViewResource($user, $item, $board));

        return $resources
            ->map(fn (PipelineBoardResource $item) => $this->serializeResource($item, $user, $board))
            ->values()
            ->all();
    }

    /** @param  list<int>  $memberUserIds
     * @return array<string, mixed>
     */
    public function createLinkResource(
        int $businessId,
        User $user,
        int $boardId,
        string $title,
        string $url,
        string $visibility,
        ?string $description = null,
        ?string $groupName = null,
        array $memberUserIds = [],
    ): array {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);
        $this->pipeline->ensureCanEditBoard($user, $board);
        $this->assertValidVisibility($visibility, $memberUserIds);

        $resource = PipelineBoardResource::create([
            'board_id' => $board->id,
            'user_id' => $user->id,
            'type' => 'link',
            'title' => $title,
            'description' => $description,
            'visibility' => $visibility,
            'group_name' => $this->normalizeGroupName($groupName),
            'url' => $this->normalizeUrl($url),
        ]);

        $this->syncMemberVisibility($resource, $board, $memberUserIds);

        return $this->serializeResource($this->reloadResource($resource), $user, $board);
    }

    /** @param  list<int>  $memberUserIds
     * @return array<string, mixed>
     */
    public function uploadResource(
        int $businessId,
        User $user,
        int $boardId,
        UploadedFile $file,
        string $visibility,
        ?string $title = null,
        ?string $description = null,
        ?string $groupName = null,
        array $memberUserIds = [],
    ): array {
        $board = $this->pipeline->getBoard($businessId, $user, $boardId);
        $this->pipeline->ensureCanEditBoard($user, $board);
        $this->assertValidVisibility($visibility, $memberUserIds);

        $path = $file->store('pipeline-board-resources', 'public');
        $fileName = $file->getClientOriginalName();
        $mimeType = $file->getClientMimeType() ?: $file->getMimeType();
        $type = Str::startsWith((string) $mimeType, 'image/') ? 'image' : 'file';

        $resource = PipelineBoardResource::create([
            'board_id' => $board->id,
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title ?: $fileName,
            'description' => $description,
            'visibility' => $visibility,
            'group_name' => $this->normalizeGroupName($groupName),
            'file_name' => $fileName,
            'file_path' => $path,
            'mime_type' => $mimeType,
            'file_size' => $file->getSize(),
        ]);

        $this->syncMemberVisibility($resource, $board, $memberUserIds);

        return $this->serializeResource($this->reloadResource($resource), $user, $board);
    }

    /** @param  list<int>  $memberUserIds
     * @return array<string, mixed>
     */
    public function updateResource(
        int $businessId,
        User $user,
        int $resourceId,
        ?string $title = null,
        ?string $description = null,
        ?string $visibility = null,
        ?string $url = null,
        ?string $groupName = null,
        array $memberUserIds = [],
        bool $groupNameProvided = false,
    ): array {
        $resource = $this->findResourceForBusiness($businessId, $resourceId);
        $board = $this->pipeline->getBoard($businessId, $user, (int) $resource->board_id);
        $this->assertCanManageResource($user, $resource, $board);

        if ($visibility !== null) {
            $this->assertValidVisibility($visibility, $memberUserIds);
            $resource->visibility = $visibility;
        }

        if ($title !== null) {
            $resource->title = $title;
        }

        if ($description !== null) {
            $resource->description = $description;
        }

        if ($groupNameProvided) {
            $resource->group_name = $this->normalizeGroupName($groupName);
        }

        if ($url !== null && $resource->type === 'link') {
            $resource->url = $this->normalizeUrl($url);
        }

        $resource->save();

        $effectiveVisibility = $visibility ?? $resource->visibility;
        if ($effectiveVisibility === 'members') {
            $this->syncMemberVisibility($resource, $board, $memberUserIds);
        } elseif ($visibility !== null && $visibility !== 'members') {
            $resource->memberLinks()->delete();
        }

        return $this->serializeResource($this->reloadResource($resource), $user, $board);
    }

    public function deleteResource(int $businessId, User $user, int $resourceId): void
    {
        $resource = $this->findResourceForBusiness($businessId, $resourceId);
        $board = $this->pipeline->getBoard($businessId, $user, (int) $resource->board_id);
        $this->assertCanManageResource($user, $resource, $board);

        if ($resource->file_path) {
            Storage::disk('public')->delete($resource->file_path);
        }

        $resource->delete();
    }

    /** @return array<string, mixed> */
    public function recordView(int $businessId, User $user, int $resourceId): array
    {
        $resource = $this->findResourceForBusiness($businessId, $resourceId);
        $board = $this->pipeline->getBoard($businessId, $user, (int) $resource->board_id);
        $this->assertCanViewResource($user, $resource, $board);

        $resource->increment('views_count');

        return $this->serializeResource($this->reloadResource($resource), $user, $board);
    }

    /** @return array<string, mixed> */
    public function recordDownload(int $businessId, User $user, int $resourceId): array
    {
        $resource = $this->findResourceForBusiness($businessId, $resourceId);
        $board = $this->pipeline->getBoard($businessId, $user, (int) $resource->board_id);
        $this->assertCanViewResource($user, $resource, $board);

        if (! in_array($resource->type, ['file', 'image'], true) || ! $resource->file_path) {
            abort(422, 'This resource does not have a downloadable file.');
        }

        $resource->increment('downloads_count');

        return $this->serializeResource($this->reloadResource($resource), $user, $board);
    }

    protected function findResourceForBusiness(int $businessId, int $resourceId): PipelineBoardResource
    {
        return PipelineBoardResource::query()
            ->whereKey($resourceId)
            ->whereHas('board', fn ($q) => $q->where('business_id', $businessId))
            ->firstOrFail();
    }

    /** @return Collection<int, PipelineBoardResource> */
    protected function loadBoardResources(PipelineBoard $board): Collection
    {
        return PipelineBoardResource::query()
            ->where('board_id', $board->id)
            ->with([
                'owner:id,name,avatar',
                'members:id,name,avatar',
            ])
            ->orderByDesc('created_at')
            ->get();
    }

    protected function reloadResource(PipelineBoardResource $resource): PipelineBoardResource
    {
        return $resource->fresh([
            'owner:id,name,avatar',
            'members:id,name,avatar',
        ]) ?? $resource;
    }

    protected function assertCanViewResource(User $user, PipelineBoardResource $resource, PipelineBoard $board): void
    {
        if (! $this->canViewResource($user, $resource, $board)) {
            abort(403, 'You do not have access to this resource.');
        }
    }

    protected function canViewResource(User $user, PipelineBoardResource $resource, PipelineBoard $board): bool
    {
        if (! $this->pipeline->canViewBoard($user, $board)) {
            return false;
        }

        if ($this->moduleAccess->isBusinessOwner($user)) {
            return true;
        }

        if ((int) $resource->user_id === (int) $user->id) {
            return true;
        }

        return match ($resource->visibility) {
            'board' => true,
            'team' => $this->isBoardTeamMember($board, $user),
            'members' => $resource->members->contains(fn (User $member) => (int) $member->id === (int) $user->id),
            'owner_only' => false,
            default => false,
        };
    }

    protected function assertCanManageResource(User $user, PipelineBoardResource $resource, PipelineBoard $board): void
    {
        if ($this->moduleAccess->isBusinessOwner($user)
            || (int) $resource->user_id === (int) $user->id
            || $this->pipeline->userCanManageBoard($user, $board)) {
            return;
        }

        abort(403, 'You cannot manage this resource.');
    }

    protected function isBoardTeamMember(PipelineBoard $board, User $user): bool
    {
        if ((int) $board->created_by === (int) $user->id) {
            return true;
        }

        return match ($board->visibility) {
            'team' => (int) $user->business_id === (int) $board->business_id && $user->is_active,
            'shared' => PipelineBoardMember::query()
                ->where('board_id', $board->id)
                ->where('user_id', $user->id)
                ->exists(),
            'private' => false,
            default => false,
        };
    }

    /** @param  list<int>  $memberUserIds */
    protected function assertValidVisibility(string $visibility, array $memberUserIds): void
    {
        if (! in_array($visibility, ['board', 'team', 'members', 'owner_only'], true)) {
            abort(422, 'Invalid resource visibility.');
        }

        if ($visibility === 'members' && count($memberUserIds) === 0) {
            abort(422, 'Select at least one team member for member-only visibility.');
        }
    }

    /** @param  list<int>  $memberUserIds */
    protected function syncMemberVisibility(PipelineBoardResource $resource, PipelineBoard $board, array $memberUserIds): void
    {
        if ($resource->visibility !== 'members') {
            $resource->memberLinks()->delete();

            return;
        }

        $allowedIds = collect($memberUserIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->filter(fn (int $id) => $id > 0)
            ->values();

        $teamIds = collect($this->boardAccessibleMemberIds($board))
            ->map(fn ($id) => (int) $id);

        $validIds = $allowedIds->intersect($teamIds)->values();

        if ($validIds->isEmpty()) {
            abort(422, 'Selected members must be part of this board.');
        }

        $resource->memberLinks()->whereNotIn('user_id', $validIds)->delete();

        foreach ($validIds as $userId) {
            PipelineBoardResourceMember::query()->firstOrCreate([
                'resource_id' => $resource->id,
                'user_id' => $userId,
            ]);
        }
    }

    /** @return list<int> */
    protected function boardAccessibleMemberIds(PipelineBoard $board): array
    {
        $ids = collect([(int) $board->created_by]);

        if ($board->project_id) {
            $project = Project::query()->find($board->project_id);
            if ($project) {
                $ids = $ids->merge($project->members()->pluck('user_id'));
            }
        }

        if ($board->visibility === 'team') {
            $ids = $ids->merge(
                User::query()
                    ->where('business_id', $board->business_id)
                    ->where('is_active', true)
                    ->pluck('id'),
            );
        } elseif ($board->visibility === 'shared') {
            $ids = $ids->merge($board->members()->pluck('user_id'));
        }

        return $ids->unique()->filter(fn ($id) => (int) $id > 0)->values()->all();
    }

    protected function normalizeGroupName(?string $groupName): ?string
    {
        if ($groupName === null) {
            return null;
        }

        $trimmed = trim($groupName);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 100);
    }

    protected function normalizeUrl(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            abort(422, 'URL is required for link resources.');
        }

        if (! Str::startsWith($trimmed, ['http://', 'https://'])) {
            $trimmed = 'https://' . $trimmed;
        }

        if (! filter_var($trimmed, FILTER_VALIDATE_URL)) {
            abort(422, 'Enter a valid URL.');
        }

        return $trimmed;
    }

    /** @return array<string, mixed> */
    protected function serializeResource(PipelineBoardResource $resource, User $viewer, PipelineBoard $board): array
    {
        $canManage = $this->moduleAccess->isBusinessOwner($viewer)
            || (int) $resource->user_id === (int) $viewer->id
            || $this->pipeline->userCanManageBoard($viewer, $board);

        $fileUrl = $resource->file_path ? url('storage/' . ltrim($resource->file_path, '/')) : null;

        return [
            'id' => $resource->id,
            'board_id' => $resource->board_id,
            'type' => $resource->type,
            'title' => $resource->title,
            'description' => $resource->description,
            'visibility' => $resource->visibility,
            'group_name' => $resource->group_name,
            'url' => $resource->url,
            'file_name' => $resource->file_name,
            'file_path' => $resource->file_path,
            'file_url' => $fileUrl,
            'mime_type' => $resource->mime_type,
            'file_size' => $resource->file_size,
            'views_count' => $resource->views_count,
            'downloads_count' => $resource->downloads_count,
            'created_at' => $resource->created_at?->toISOString(),
            'updated_at' => $resource->updated_at?->toISOString(),
            'owner' => $resource->owner ? [
                'id' => $resource->owner->id,
                'name' => $resource->owner->name,
                'avatar' => $resource->owner->avatar,
            ] : null,
            'members' => $resource->members->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'avatar' => $member->avatar,
            ])->values()->all(),
            'can_manage' => $canManage,
            'can_edit' => $canManage,
            'can_delete' => $canManage,
        ];
    }
}
