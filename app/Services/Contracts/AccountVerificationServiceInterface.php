<?php

namespace App\Services\Contracts;

use App\Models\User;

interface AccountVerificationServiceInterface
{
    public const PURPOSE_EMAIL_VERIFICATION = 'email_verification';
    public const PURPOSE_TWO_FACTOR = 'two_factor';
    public const PURPOSE_PASSWORD_CHANGE = 'password_change';
    public const PURPOSE_PROFILE_CHANGE = 'profile_change';
    public const PURPOSE_DELETE_ACCOUNT = 'delete_account';
    public const PURPOSE_LINK_ACCOUNT = 'link_account';
    public const PURPOSE_UNLINK_ACCOUNT = 'unlink_account';

    public function issue(User $user, string $purpose, ?string $ip = null, ?string $userAgent = null, array $context = []): void;

    public function verify(User $user, string $purpose, string $code): array|null;
}
