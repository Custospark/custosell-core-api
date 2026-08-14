<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Customer;
use App\Models\DocumentCabinet;
use App\Models\DocumentFolder;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;

class DocumentFolderResolver
{
    public function __construct(
        protected DocumentAccessService $access,
    ) {}

    public function assertFolderUploadAccess(int $businessId, User $user, ?int $folderId, ?int $cabinetId = null): void
    {
        if ($folderId === null) {
            if ($cabinetId === null) {
                abort(422, 'Cabinet is required for root uploads.');
            }
            $cabinet = DocumentCabinet::query()
                ->where('business_id', $businessId)
                ->whereKey($cabinetId)
                ->firstOrFail();
            $this->access->assertCanContributeToCabinet($user, $cabinet);

            return;
        }

        $folder = DocumentFolder::query()
            ->where('business_id', $businessId)
            ->whereKey($folderId)
            ->firstOrFail();

        $this->access->assertCanContributeToFolder($user, $folder);
    }

    public function resolveCabinetIdForWrite(int $businessId, User $user, ?int $folderId, ?int $cabinetId): int
    {
        if ($folderId !== null) {
            $folder = DocumentFolder::query()
                ->where('business_id', $businessId)
                ->whereKey($folderId)
                ->firstOrFail();

            return (int) $folder->cabinet_id;
        }

        if ($cabinetId === null) {
            abort(422, 'Cabinet is required.');
        }

        $cabinet = DocumentCabinet::query()
            ->where('business_id', $businessId)
            ->whereKey($cabinetId)
            ->firstOrFail();
        $this->access->assertCanContributeToCabinet($user, $cabinet);

        return $cabinetId;
    }

    public function assertLinkedEntities(int $businessId, ?int $customerId, ?int $projectId): void
    {
        if ($customerId !== null) {
            Customer::query()->where('business_id', $businessId)->whereKey($customerId)->firstOrFail();
        }

        if ($projectId !== null) {
            Project::query()->where('business_id', $businessId)->whereKey($projectId)->firstOrFail();
        }
    }

    public function normalizeUrl(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            abort(422, 'URL is required for link documents.');
        }

        if (! Str::startsWith($trimmed, ['http://', 'https://'])) {
            $trimmed = 'https://'.$trimmed;
        }

        if (! filter_var($trimmed, FILTER_VALIDATE_URL)) {
            abort(422, 'Enter a valid URL.');
        }

        return $trimmed;
    }
}
