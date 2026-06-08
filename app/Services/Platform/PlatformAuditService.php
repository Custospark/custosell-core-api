<?php

namespace App\Services\Platform;

use App\Models\PlatformAuditLog;
use App\Models\User;

class PlatformAuditService
{
    public function log(
        ?User $actor,
        string $action,
        string $targetType,
        int $targetId,
        ?string $reason = null,
        ?array $metadata = null,
    ): PlatformAuditLog {
        return PlatformAuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }
}
