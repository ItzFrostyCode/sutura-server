<?php

namespace App\Support;

/**
 * Keeps texts cheap. One SMS = 160 characters only while every character is in the basic GSM-7 alphabet; a single
 * "₱", curly quote or emoji flips the whole message to UCS-2 where one SMS is just 70 characters. So text is
 * folded to plain ASCII first, and the segment count (what the provider bills) is calculated up front.
 */
class SmsText
{
    private const MAP = [
        '₱' => 'PHP ', '–' => '-', '—' => '-', '‘' => "'", '’' => "'", '“' => '"', '”' => '"', '…' => '...', '•' => '-', '·' => '-',
        'ñ' => 'n', 'Ñ' => 'N', 'é' => 'e', 'É' => 'E', 'á' => 'a', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', "\u{00A0}" => ' ',
    ];

    public static function clean(string $text): string
    {
        $text = strtr($text, self::MAP);
        $text = preg_replace('/[^\x20-\x7E\n]/', '', $text) ?? '';   // anything still outside printable ASCII is dropped

        return trim(preg_replace('/[ \t]+/', ' ', $text) ?? '');
    }

    /** Characters that cost two positions in GSM-7. */
    private const EXTENDED = '^{}\\[~]|';

    /** @return array{chars:int, segments:int, per_segment:int} */
    public static function measure(string $text): array
    {
        $len = 0;
        foreach (str_split($text) as $c) {
            $len += str_contains(self::EXTENDED, $c) ? 2 : 1;
        }
        $segments = $len <= 160 ? 1 : (int) ceil($len / 153);

        return ['chars' => $len, 'segments' => max(1, $segments), 'per_segment' => $segments <= 1 ? 160 : 153];
    }

    /** Shortens a name for the template, keeping it readable. */
    public static function fit(?string $value, int $max): string
    {
        $value = self::clean((string) $value);

        return strlen($value) <= $max ? $value : rtrim(substr($value, 0, $max - 1)).'.';
    }

    /** A shop name cut at a word boundary: "Thread & Needle Tailoring Services" -> "Thread & Needle Tailoring". */
    public static function shopName(?string $value, int $max = 26): string
    {
        $value = self::clean((string) $value);
        if (strlen($value) <= $max) {
            return $value;
        }
        $cut = substr($value, 0, $max + 1);
        $space = strrpos($cut, ' ');

        return rtrim($space !== false && $space >= 8 ? substr($cut, 0, $space) : substr($value, 0, $max), " &,-");
    }
}
