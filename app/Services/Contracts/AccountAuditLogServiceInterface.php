<?php

namespace App\Services\Contracts;

use App\Models\User;
use Illuminate\Support\Collection;

interface AccountAuditLogServiceInterface
{
    public function log(User $user, string $action, array $context = [], ?string $ip = null, ?string $userAgent = null): void;

    public function feed(User $user): Collection;
}
