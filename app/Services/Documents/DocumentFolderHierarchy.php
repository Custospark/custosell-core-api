<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\DocumentFolder;

class DocumentFolderHierarchy
{
    public function move(int $businessId, DocumentFolder $folder, int $newParentId): void
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

        $depthDelta = $newDepth - $folder->depth;
        $folder->parent_id = $newParent->id;
        $folder->depth = $newDepth;
        $this->applyDepthDelta($folder, $businessId, $depthDelta);
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

    public function applyDepthDelta(DocumentFolder $folder, int $businessId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $children = DocumentFolder::query()
            ->where('business_id', $businessId)
            ->where('parent_id', $folder->id)
            ->get();

        foreach ($children as $child) {
            $child->depth += $delta;
            $child->save();
            $this->applyDepthDelta($child, $businessId, $delta);
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
}
