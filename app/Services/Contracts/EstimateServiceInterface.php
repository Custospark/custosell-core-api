<?php

namespace App\Services\Contracts;

use App\Models\Estimate;
use App\Models\EstimateTemplate;
use App\Models\Invoice;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;

interface EstimateServiceInterface
{
    public function getAll(int $businessId, array $filters = []): Collection;

    public function getById(int $id): ?Estimate;

    public function create(int $businessId, int $userId, array $data): Estimate;

    public function update(int $id, array $data): Estimate;

    public function delete(int $id): bool;

    public function send(int $id, int $userId, ?string $changeSummary = null): Estimate;

    public function approve(int $id, ?string $approvedByName = null): Estimate;

    public function reject(int $id, string $reason): Estimate;

    public function duplicate(int $id, int $userId): Estimate;

    public function createRevision(int $id, int $userId, array $data): Estimate;

    public function convertToInvoice(int $id, int $userId, array $options = []): Invoice;

    public function convertToProject(int $id, int $userId, array $options = []): Project;

    public function analyticsSummary(int $businessId): array;

    public function getTemplates(int $businessId): Collection;

    public function createTemplate(int $businessId, int $userId, array $data): EstimateTemplate;

    public function updateTemplate(int $id, array $data): EstimateTemplate;

    public function deleteTemplate(int $id): bool;
}
