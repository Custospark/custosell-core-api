<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineLead;
use App\Models\PipelineSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class PipelineSourceService
{
    public function seedSourcesIfMissing(int $businessId): void
    {
        if (PipelineSource::query()->where('business_id', $businessId)->exists()) {
            return;
        }

        $defaults = [
            ['name' => 'Walk-in', 'sort_order' => 1],
            ['name' => 'Referral', 'sort_order' => 2],
            ['name' => 'Website', 'sort_order' => 3],
            ['name' => 'Phone', 'sort_order' => 4],
            ['name' => 'Other', 'sort_order' => 5],
        ];

        foreach ($defaults as $row) {
            PipelineSource::create([
                'business_id' => $businessId,
                'name' => $row['name'],
                'is_system' => true,
                'sort_order' => $row['sort_order'],
            ]);
        }
    }

    public function listSources(int $businessId): Collection
    {
        $this->seedSourcesIfMissing($businessId);

        return PipelineSource::query()
            ->where('business_id', $businessId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function createSource(int $businessId, User $user, array $data): PipelineSource
    {
        $maxOrder = PipelineSource::query()->where('business_id', $businessId)->max('sort_order');

        return PipelineSource::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'is_system' => false,
            'sort_order' => (int) ($data['sort_order'] ?? ($maxOrder + 1)),
        ]);
    }

    public function updateSource(int $businessId, int $sourceId, array $data): PipelineSource
    {
        $source = PipelineSource::query()
            ->where('business_id', $businessId)
            ->where('id', $sourceId)
            ->firstOrFail();

        if ($source->is_system && array_key_exists('name', $data)) {
            throw ValidationException::withMessages(['name' => 'System sources cannot be renamed.']);
        }

        $source->update(array_filter([
            'name' => $data['name'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
        ], fn ($v) => $v !== null));

        return $source->fresh();
    }

    public function deleteSource(int $businessId, int $sourceId): void
    {
        $source = PipelineSource::query()
            ->where('business_id', $businessId)
            ->where('id', $sourceId)
            ->firstOrFail();

        if ($source->is_system) {
            throw ValidationException::withMessages(['source' => 'System sources cannot be deleted.']);
        }

        PipelineLead::query()->where('source_id', $sourceId)->update(['source_id' => null]);
        $source->delete();
    }
}
