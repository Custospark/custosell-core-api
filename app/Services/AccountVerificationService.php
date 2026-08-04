<?php

namespace App\Services;

use App\Mail\StandardEmail;
use App\Models\AccountVerificationCode;
use App\Models\User;
use App\Services\Contracts\AccountVerificationServiceInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AccountVerificationService implements AccountVerificationServiceInterface
{
    public function issue(User $user, string $purpose, ?string $ip = null, ?string $userAgent = null): void
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
            'expires_at' => now()->addMinutes($ttl),
        ]);

        Mail::to($user->email)->send(new StandardEmail(
            title: $purpose === self::PURPOSE_TWO_FACTOR
                ? 'Your Custosell sign-in code'
                : 'Verify your Custosell email',
            mailBody: "Your security code is <strong>{$code}</strong>.\n\nIt expires in {$ttl} minutes. If you didn't request this, you can safely ignore this email.",
            ctaLabel: 'Sign in to Custosell',
            ctaUrl: config('app.frontend_url', config('app.url')),
            tip: $ip && $userAgent
                ? "This request came from {$ip} using {$userAgent}."
                : null,
        ));
    }

    public function verify(User $user, string $purpose, string $code): bool
    {
        $record = AccountVerificationCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (! $record || ! Hash::check($code, $record->code_hash)) {
            return false;
        }

        $record->update(['used_at' => now()]);

        return true;
    }
}
