<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Models\User;

interface AssistantToolInterface
{
    /** Stable snake_case name the model requests, e.g. sales_today. */
    public function name(): string;

    /** One-line purpose shown to the model. */
    public function description(): string;

    /**
     * Strict argument schema: ['arg' => ['type' => 'int|string', 'required' => bool,
     * 'min' => int, 'max' => int, 'default' => mixed, 'in' => list<string>]].
     * Business/user identity NEVER comes from args - always the auth user.
     *
     * @return array<string, array<string, mixed>>
     */
    public function parameters(): array;

    /** Module gate enforced before execution (plan + grants). */
    public function requiredModule(): string;

    /** Canonical read endpoint this tool mirrors, e.g. GET /sales/daily. */
    public function endpoint(): string;

    /**
     * Execute with server-side scope only. Returns plain JSON-safe data;
     * ['error' => string] when denied or unanswerable (model relays it).
     *
     * @return array<string, mixed>
     */
    public function execute(User $user, array $args): array;
}
