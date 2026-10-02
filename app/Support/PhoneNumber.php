<?php

namespace App\Support;

/** Philippine mobile numbers: accepts 09171234567, 9171234567, 639171234567, +63 917 123 4567 … */
class PhoneNumber
{
    /** @return string|null  "+639171234567", or null when it is not a usable PH mobile number */
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = '63'.substr($digits, 1);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '63'.$digits;
        }

        return preg_match('/^639\d{9}$/', $digits) ? '+'.$digits : null;
    }

    /** "+63 917 *** 4567" — enough to recognise, not enough to copy. */
    public static function mask(?string $normalized): string
    {
        return $normalized ? substr($normalized, 0, 3).' '.substr($normalized, 3, 3).' *** '.substr($normalized, -4) : '—';
    }
}
