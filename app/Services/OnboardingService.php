<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class OnboardingService
{
    public const INTENT_IDS = [
        'dashboard',
        'sales',
        'inventory',
        'customers',
        'pipeline',
        'estimates',
        'expenses',
        'documents',
        'hr',
        'accounting',
        'forecasting',
        'explore',
        // Legacy aliases
        'sell_pos',
        'get_paid',
        'buy_supply',
        'win_deals',
        'run_projects',
        'people_payroll',
        'know_numbers',
    ];

    public function isBusinessOwner(User $user): bool
    {
        if ($user->business_id === null) {
            return false;
        }

        $ownerId = $user->business?->owner_id;
        if ($ownerId === null && $user->relationLoaded('business') === false) {
            $ownerId = Business::query()->whereKey($user->business_id)->value('owner_id');
        }

        return $ownerId !== null && (int) $ownerId === (int) $user->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadFor(User $user): array
    {
        $user->loadMissing('business');
        $business = $user->business;
        $isOwner = $this->isBusinessOwner($user);

        $intentDone = $business
            && ($business->intent_completed_at !== null || $business->intent_skipped_at !== null);
        $tourDone = $user->tour_completed_at !== null || $user->tour_skipped_at !== null;

        return [
            'is_owner' => $isOwner,
            'needs_intent' => $isOwner && ! $intentDone,
            'needs_tour' => ! $tourDone,
            'primary_intent' => $business?->primary_intent,
            'secondary_intent' => $business?->secondary_intent,
            'intent_completed_at' => $business?->intent_completed_at?->toISOString(),
            'intent_skipped_at' => $business?->intent_skipped_at?->toISOString(),
            'tour_step' => (int) ($user->tour_step ?? 0),
            'tour_completed_at' => $user->tour_completed_at?->toISOString(),
            'tour_skipped_at' => $user->tour_skipped_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(User $user, array $data): array
    {
        $user->loadMissing('business');
        $action = (string) ($data['action'] ?? '');

        return match ($action) {
            'complete_intent' => $this->completeIntent($user, $data),
            'skip_intent' => $this->skipIntent($user),
            'tour_step' => $this->saveTourStep($user, $data),
            'complete_tour' => $this->completeTour($user),
            'skip_tour' => $this->skipTour($user),
            'replay_tour' => $this->replayTour($user),
            default => throw ValidationException::withMessages([
                'action' => 'Unknown onboarding action.',
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function completeIntent(User $user, array $data): array
    {
        if (! $this->isBusinessOwner($user)) {
            throw ValidationException::withMessages([
                'action' => 'Only the business owner can set workspace intent.',
            ]);
        }

        $business = $user->business;
        if (! $business) {
            throw ValidationException::withMessages([
                'action' => 'No business found for this account.',
            ]);
        }

        $primary = (string) ($data['primary_intent'] ?? '');
        $secondary = $data['secondary_intent'] ?? null;
        if ($secondary === '') {
            $secondary = null;
        }

        if (! in_array($primary, self::INTENT_IDS, true)) {
            throw ValidationException::withMessages([
                'primary_intent' => 'Invalid primary intent.',
            ]);
        }

        if ($secondary !== null && ! in_array((string) $secondary, self::INTENT_IDS, true)) {
            throw ValidationException::withMessages([
                'secondary_intent' => 'Invalid secondary intent.',
            ]);
        }

        if ($secondary !== null && (string) $secondary === $primary) {
            $secondary = null;
        }

        // Preference only — never mutates module access.
        $business->forceFill([
            'primary_intent' => $primary,
            'secondary_intent' => $secondary,
            'intent_completed_at' => now(),
            'intent_skipped_at' => null,
        ])->save();

        return $this->payloadFor($user->fresh(['business']));
    }

    /**
     * @return array<string, mixed>
     */
    protected function skipIntent(User $user): array
    {
        if (! $this->isBusinessOwner($user)) {
            throw ValidationException::withMessages([
                'action' => 'Only the business owner can skip workspace intent.',
            ]);
        }

        $business = $user->business;
        if (! $business) {
            throw ValidationException::withMessages([
                'action' => 'No business found for this account.',
            ]);
        }

        $business->forceFill([
            'intent_skipped_at' => now(),
            'intent_completed_at' => null,
        ])->save();

        return $this->payloadFor($user->fresh(['business']));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function saveTourStep(User $user, array $data): array
    {
        $step = max(0, (int) ($data['tour_step'] ?? 0));
        $user->forceFill([
            'tour_step' => $step,
            'tour_completed_at' => null,
            'tour_skipped_at' => null,
        ])->save();

        return $this->payloadFor($user->fresh(['business']));
    }

    /**
     * @return array<string, mixed>
     */
    protected function completeTour(User $user): array
    {
        $user->forceFill([
            'tour_completed_at' => now(),
            'tour_skipped_at' => null,
        ])->save();

        return $this->payloadFor($user->fresh(['business']));
    }

    /**
     * @return array<string, mixed>
     */
    protected function skipTour(User $user): array
    {
        $user->forceFill([
            'tour_skipped_at' => now(),
            'tour_completed_at' => null,
        ])->save();

        return $this->payloadFor($user->fresh(['business']));
    }

    /**
     * @return array<string, mixed>
     */
    protected function replayTour(User $user): array
    {
        $user->forceFill([
            'tour_step' => 0,
            'tour_completed_at' => null,
            'tour_skipped_at' => null,
        ])->save();

        return $this->payloadFor($user->fresh(['business']));
    }
}
