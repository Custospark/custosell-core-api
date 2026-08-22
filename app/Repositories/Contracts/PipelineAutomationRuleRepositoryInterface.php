<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\PipelineAutomationRule;
use Illuminate\Support\Collection;

interface PipelineAutomationRuleRepositoryInterface
{
    /** @return Collection<int, PipelineAutomationRule> */
    public function forBoard(int $boardId): Collection;

    /** @return Collection<int, PipelineAutomationRule> */
    public function activeScheduledRules(): Collection;

    public function find(int $id): ?PipelineAutomationRule;

    /** @param  array<string, mixed>  $data */
    public function create(array $data): PipelineAutomationRule;

    /** @param  array<string, mixed>  $data */
    public function update(PipelineAutomationRule $rule, array $data): PipelineAutomationRule;

    public function delete(PipelineAutomationRule $rule): bool;

    public function markRun(PipelineAutomationRule $rule, bool $success = true): void;
}