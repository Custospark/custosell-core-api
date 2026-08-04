<?php

namespace App\Services;

use App\Models\AccountAuditLog;
use App\Models\User;
use App\Services\Contracts\AccountAuditLogServiceInterface;
use Illuminate\Support\Collection;

class AccountAuditLogService implements AccountAuditLogServiceInterface
{
    public function log(User $user, string $action, array $context = [], ?string $ip = null, ?string $userAgent = null): void
    {
        AccountAuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'context' => $context ?: null,
            'ip_address' => $ip,
            'user_agent' => $userAgent ? substr($userAgent, 0, 512) : null,
        ]);
    }

    public function feed(User $user): Collection
    {
        return AccountAuditLog::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function (AccountAuditLog $log): array {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'context' => $log->context,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'at' => $log->created_at->toISOString(),
                ];
            })
            ->values();
    }
}
