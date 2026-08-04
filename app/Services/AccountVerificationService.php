<?php

namespace App\Services;

use App\Mail\StandardEmail;
use App\Models\AccountVerificationCode;
use App\Models\User;
use App\Services\Contracts\AccountVerificationServiceInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AccountVerificationService implements AccountVerificationServiceInterface
{
    public function issue(User $user, string $purpose, ?string $ip = null, ?string $userAgent = null, array $context = []): void
    {
        $code = (string) random_int(100000, 999999);

        AccountVerificationCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->delete();

        $ttl = (int) config('auth.verification.code_ttl_minutes', 10);

        AccountVerificationCode::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'context' => $context ?: null,
            'expires_at' => now()->addMinutes($ttl),
        ]);

        $title = match ($purpose) {
            self::PURPOSE_TWO_FACTOR => 'Your Custosell sign-in code',
            self::PURPOSE_PASSWORD_CHANGE => 'Confirm your Custosell password change',
            self::PURPOSE_PROFILE_CHANGE => 'Confirm your Custosell profile change',
            default => 'Verify your Custosell email',
        };

        Mail::to($user->email)->send(new StandardEmail(
            title: $title,
            mailBody: "Your security code is <strong>{$code}</strong>.\n\nIt expires in {$ttl} minutes. If you didn't request this, you can safely ignore this email.",
            ctaLabel: 'Sign in to Custosell',
            ctaUrl: config('app.frontend_url', config('app.url')),
            tip: $ip && $userAgent
                ? "This request came from {$ip} using {$userAgent}."
                : null,
        ));
    }

    /**
     * Verify a code for the given user + purpose. On success the code is consumed
     * and any context carried by the issuing request (e.g. pending password hash)
     * is returned; returns null when invalid/expired/absent.
     */
    public function verify(User $user, string $purpose, string $code): array|null
    {
        $record = AccountVerificationCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (! $record || ! Hash::check($code, $record->code_hash)) {
            return null;
        }

        $record->update(['used_at' => now()]);

        return is_array($record->context) && $record->context !== []
            ? $record->context
            : ['verified' => true];
    }
}
