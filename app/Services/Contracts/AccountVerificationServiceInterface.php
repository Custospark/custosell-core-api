<?php

namespace App\Services\Contracts;

use App\Models\User;

interface AccountVerificationServiceInterface
{
    public const PURPOSE_EMAIL_VERIFICATION = 'email_verification';
    public const PURPOSE_TWO_FACTOR = 'two_factor';

    public function issue(User $user, string $purpose, ?string $ip = null, ?string $userAgent = null): void;

    public function verify(User $user, string $purpose, string $code): bool;
}
