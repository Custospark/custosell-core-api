<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Models\PipelineAutomationRule;
use App\Models\User;

interface PipelineAutomationRuleServiceInterface
{
    /** @return list<array<string, mixed>> */
    public function listForBoard(int $businessId, User $user, int $boardId): array;

    /** @param  array<string, mixed>  $data */
    public function createRule(int $businessId, User $user, int $boardId, array $data): array;

    /** @param  array<string, mixed>  $data */
    public function updateRule(int $businessId, User $user, int $ruleId, array $data): array;

    public function deleteRule(int $businessId, User $user, int $ruleId): void;

    public function toggleRule(int $businessId, User $user, int $ruleId, bool $active): array;

    /** @return array<string, mixed> */
    public function serialize(PipelineAutomationRule $rule): array;
}