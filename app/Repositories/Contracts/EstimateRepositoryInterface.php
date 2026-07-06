<?php

namespace App\Repositories\Contracts;

use App\Models\Estimate;
use App\Models\EstimateTemplate;
use Illuminate\Database\Eloquent\Collection;

interface EstimateRepositoryInterface
{
    public function all(int $businessId, array $filters = []): Collection;

    public function find(int $id): ?Estimate;

    public function findByNumber(int $businessId, string $number): ?Estimate;

    public function create(array $data): Estimate;

    public function update(Estimate $estimate, array $data): Estimate;

    public function delete(Estimate $estimate): bool;

    public function templates(int $businessId): Collection;

    public function findTemplate(int $id): ?EstimateTemplate;

    public function createTemplate(array $data): EstimateTemplate;

    public function updateTemplate(EstimateTemplate $template, array $data): EstimateTemplate;

    public function deleteTemplate(EstimateTemplate $template): bool;

    public function analyticsSummary(int $businessId): array;
}
