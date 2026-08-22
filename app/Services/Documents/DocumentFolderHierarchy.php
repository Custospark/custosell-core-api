<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\DocumentFolder;

class DocumentFolderHierarchy
{
    public function move(int $businessId, DocumentFolder $folder, int $newParentId, ?int $cabinetId = null): void
    {
        if ($newParentId === $folder->id) {
            abort(422, 'A folder cannot be moved into itself.');
        }

        $newParent = $this->findFolder($businessId, $newParentId);
        if ($this->isDescendant($newParent, $folder->id)) {
            abort(422, 'A folder cannot be moved into its own subfolder.');
        }

        $newDepth = $newParent->depth + 1;
        $maxSubtreeDepth = $this->maxSubtreeDepth($folder);
        if ($newDepth + $maxSubtreeDepth - 1 > (int) config('documents.max_depth', 5)) {
            abort(422, 'Move would exceed maximum folder depth.');
        }

        // When the destination parent lives in a different cabinet, the whole
        // subtree (folders and their documents) moves cabinets too.
        $targetCabinetId = (int) $newParent->cabinet_id;
        if ($cabinetId !== null && (int) $cabinetId !== (int) $folder->cabinet_id) {
            $targetCabinetId = (int) $cabinetId;
        }

        $depthDelta = $newDepth - $folder->depth;
        $folder->parent_id = $newParent->id;
        $folder->depth = $newDepth;
        $folder->cabinet_id = $targetCabinetId;
        $this->applyDepthDelta($folder, $businessId, $depthDelta, $targetCabinetId);
        if ((int) $folder->cabinet_id !== (int) $targetCabinetId) {
            $folder->save();
        }
    }

    public function isDescendant(DocumentFolder $candidate, int $ancestorId): bool
    {
        $current = $candidate;
        $visited = [];

        while ($current !== null) {
            if (in_array($current->id, $visited, true)) {
                return false;
            }
            $visited[] = $current->id;

            if ((int) $current->id === $ancestorId) {
                return true;
            }

            if ($current->parent_id === null) {
                return false;
            }

            $current = DocumentFolder::query()->find($current->parent_id);
        }

        return false;
    }

    public function maxSubtreeDepth(DocumentFolder $folder): int
    {
        $children = DocumentFolder::query()->where('parent_id', $folder->id)->get();
        if ($children->isEmpty()) {
            return 1;
        }

        return 1 + $children->map(fn (DocumentFolder $child) => $this->maxSubtreeDepth($child))->max();
    }

    public function applyDepthDelta(DocumentFolder $folder, int $businessId, int $delta, ?int $cabinetId = null): void
    {
        if ($delta === 0 && $cabinetId === null) {
            return;
        }

        $children = DocumentFolder::query()
            ->where('business_id', $businessId)
            ->where('parent_id', $folder->id)
            ->get();

        foreach ($children as $child) {
            $child->depth += $delta;
            if ($cabinetId !== null && (int) $child->cabinet_id !== (int) $cabinetId) {
                $child->cabinet_id = (int) $cabinetId;
            }
            $child->save();
            $this->applyDepthDelta($child, $businessId, $delta, $cabinetId);
        }

        // Documents nested under this folder move cabinets too.
        if ($cabinetId !== null) {
            \App\Models\Document::query()
                ->where('business_id', $businessId)
                ->where('folder_id', $folder->id)
                ->where('cabinet_id', '!=', (int) $cabinetId)
                ->update(['cabinet_id' => (int) $cabinetId]);
        }
    }

    /** @return list<array{id: int, name: string}> */
    public function breadcrumbs(DocumentFolder $folder): array
    {
        $crumbs = [];
        $current = $folder;

        while ($current !== null) {
            array_unshift($crumbs, ['id' => $current->id, 'name' => $current->name]);
            if ($current->parent_id === null) {
                break;
            }
            $current = DocumentFolder::query()->find($current->parent_id);
        }

        return $crumbs;
    }

    public function findFolder(int $businessId, int $folderId): DocumentFolder
    {
        return DocumentFolder::query()
            ->where('business_id', $businessId)
            ->whereKey($folderId)
            ->firstOrFail();
    }

    /**
     * Move a folder to the root of a (possibly different) cabinet. Re-cabinet
     * the whole subtree and reset depths from the top.
     */
    public function moveToRoot(int $businessId, DocumentFolder $folder, int $cabinetId, bool $changeCabinet): void
    {
        $maxSubtreeDepth = $this->maxSubtreeDepth($folder);
        if ($maxSubtreeDepth > (int) config('documents.max_depth', 5)) {
            abort(422, 'Move would exceed maximum folder depth.');
        }

        $delta = 1 - $folder->depth;
        $folder->parent_id = null;
        $folder->depth = 1;
        $targetCabinet = $changeCabinet ? $cabinetId : (int) $folder->cabinet_id;
        $folder->cabinet_id = $targetCabinet;
        $this->applyDepthDelta($folder, $businessId, $delta, $changeCabinet ? $cabinetId : null);
        $folder->save();
    }
}
