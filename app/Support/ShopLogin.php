<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Admin-issued shop credentials. Shop logins live on their own domain
 * (config app.shop_login_domain) so a shop account can never collide with a
 * customer's personal Gmail, and the two sign-ins stay clearly separate.
 */
class ShopLogin
{
    public static function domain(): string
    {
        return config('app.shop_login_domain', 'sutura.shop');
    }

    /** e.g. "Thread & Needle Tailoring" → threadneedletailoring@sutura.shop (numbered if taken). */
    public static function suggest(string $storeName, ?int $ignoreUserId = null): string
    {
        $base = Str::limit(preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($storeName))), 30, '') ?: 'shop';

        for ($i = 1; ; $i++) {
            $candidate = ($i === 1 ? $base : $base.$i).'@'.self::domain();
            if (! self::taken($candidate, $ignoreUserId)) {
                return $candidate;
            }
        }
    }

    public static function taken(string $email, ?int $ignoreUserId = null): bool
    {
        return User::withTrashed()
            ->where('email', $email)
            ->when($ignoreUserId, fn ($q) => $q->where('id', '!=', $ignoreUserId))
            ->exists();
    }

    /** Validation regex for an admin-edited login: local part + the shop domain. */
    public static function pattern(): string
    {
        return '/^[a-z0-9][a-z0-9._-]{1,40}@'.preg_quote(self::domain(), '/').'$/';
    }

    /**
     * Temporary password that satisfies Password::defaults() (mixed case,
     * number, symbol) and avoids look-alike characters, since an admin may
     * read it out over the phone.
     */
    public static function temporaryPassword(): string
    {
        $pick = fn (string $set, int $n) => collect(range(1, $n))->map(fn () => $set[random_int(0, strlen($set) - 1)])->implode('');

        $chars = $pick('ABCDEFGHJKLMNPQRSTUVWXYZ', 3)
            .$pick('abcdefghijkmnpqrstuvwxyz', 5)
            .$pick('23456789', 3)
            .$pick('@#$%&*!', 1);

        return str_shuffle($chars);
    }
}
