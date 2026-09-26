<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Models\User;
use App\Services\Assistant\AssistantException;
use App\Services\ModuleAccessService;
use Illuminate\Support\Facades\Log;

/**
 * Allowlisted read-only tools. The model may only request names registered
 * here; every execution is scoped to the authenticated user server-side, so
 * prompt manipulation can never reach another business.
 */
class AssistantToolExecutor
{
    /** @var array<string, AssistantToolInterface> */
    private array $tools = [];

    public function __construct(
        iterable $tools,
        private ModuleAccessService $moduleAccess,
    ) {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /** @return list<array{name: string, description: string, endpoint: string, parameters: array<string, array<string, mixed>>}> */
    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->tools as $tool) {
            $definitions[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'endpoint' => $tool->endpoint(),
                'parameters' => $tool->parameters(),
            ];
        }

        return $definitions;
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user, string $name, array $args): array
    {
        $tool = $this->tools[$name] ?? null;
        if (! $tool) {
            Log::warning('Assistant unknown tool requested', ['tool' => substr($name, 0, 64), 'user_id' => $user->id]);

            return ['error' => "Unknown tool '{$name}'. Answer without live data or say you cannot."];
        }

        try {
            return $tool->execute($user, $args);
        } catch (AssistantException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::warning('Assistant tool failed', ['tool' => $name, 'user_id' => $user->id, 'error' => $e->getMessage()]);

            return ['error' => 'Live data is temporarily unavailable - answer without it or say so.'];
        }
    }
}
