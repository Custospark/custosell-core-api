<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Subscription;
use Illuminate\Validation\ValidationException;

class BoardMemberLimitService
{
    public function assertWithinBoardMemberLimit(Subscription $subscription, int $currentCount, int $newCount): void
    {
        $limits = $subscription?->plan?->limits ?? [];
        $maxMembers = $limits['max_board_members'] ?? null;

        if ($maxMembers === null) {
            return;
        }

        if ($newCount > $maxMembers) {
            throw ValidationException::withMessages([
                'members' => "You can add up to {$maxMembers} members per board on your current plan. Upgrade to add more.",
            ]);
        }

        if ($currentCount > $maxMembers) {
            throw ValidationException::withMessages([
                'members' => "Your current plan allows up to {$maxMembers} members per board. Remove some members or upgrade your plan.",
            ]);
        }
    }
}
