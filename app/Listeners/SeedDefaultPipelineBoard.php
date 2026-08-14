<?php

namespace App\Listeners;

use App\Events\UserRegistered;
use App\Services\Pipeline\PipelineBoardService;
use Illuminate\Support\Facades\Log;

/**
 * Seed a default pipeline board (stages, labels, guiding cards) the moment an
 * account is created - personal workspaces and business accounts alike - so the
 * CRM board is never empty on first sign-in. Storefront buyers have no business
 * workspace, so they are skipped. Seeding failures never block registration.
 */
class SeedDefaultPipelineBoard
{
    public function __construct(
        protected PipelineBoardService $boards,
    ) {}

    public function handle(UserRegistered $event): void
    {
        $user = $event->user;
        $business = $event->business ?? $user->business;

        if (!$business) {
            return;
        }

        try {
            $this->boards->ensureBusinessSetup((int) $business->id, (int) $user->id);
        } catch (\Throwable $e) {
            Log::warning('Default pipeline board could not be seeded', [
                'user_id' => $user->id ?? null,
                'business_id' => $business->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
