<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Models\User;
use App\Services\Assistant\AssistantException;
use App\Services\ModuleAccessService;
use Illuminate\Support\Facades\Log;

abstract class AbstractAssistantTool implements AssistantToolInterface
{
    public function __construct(
        protected ModuleAccessService $moduleAccess,
    ) {}

    protected function businessId(User $user): int
    {
        if (! $user->business_id) {
            throw new AssistantException('This question needs a business account.');
        }

        return (int) $user->business_id;
    }

    /** Module gate - returns denial payload instead of throwing, so the model relays it gracefully. */
    protected function denied(User $user): ?array
    {
        if (! $this->moduleAccess->canAccess($user, $this->requiredModule())) {
            return ['error' => "You do not have access to {$this->requiredModule()} on this account."];
        }

        return null;
    }

    protected function argInt(array $args, string $key, int $default, int $min, int $max): int
    {
        $value = $args[$key] ?? $default;
        if (! is_numeric($value)) {
            throw new AssistantException("Invalid '{$key}' - expected a number.");
        }

        return max($min, min($max, (int) $value));
    }

    protected function argString(array $args, string $key, int $maxLength = 120): string
    {
        $value = trim((string) ($args[$key] ?? ''));

        return mb_substr($value, 0, $maxLength);
    }

    protected function audit(User $user, string $detail = ''): void
    {
        Log::info('Assistant tool executed', [
            'tool' => $this->name(),
            'endpoint' => $this->endpoint(),
            'user_id' => $user->id,
            'business_id' => $user->business_id,
            'detail' => $detail,
        ]);
    }
}
