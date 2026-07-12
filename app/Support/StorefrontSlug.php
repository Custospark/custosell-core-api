<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

final class StorefrontSlug
{
    /** @var list<string> */
    public const RESERVED = [
        'discover', 'login', 'register', 'pricing', 'privacy', 'api', 'admin',
        'settings', 'dashboard', 'shop', 'store', 'help', 'support', 'www',
        'app', 'assets', 'static', 'auth', 'forgot-password', 'reset-password',
    ];

    public static function normalize(string $slug): string
    {
        return Str::slug(trim($slug));
    }

    public static function isReserved(string $slug): bool
    {
        return in_array(strtolower($slug), self::RESERVED, true);
    }

    public static function isValidFormat(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)
            && strlen($slug) >= 2
            && strlen($slug) <= 80;
    }
}
