<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A link a shop or customer types in that other people will later click. Anything that names a
 * scheme must be http(s) — `javascript:`, `data:`, `vbscript:` and friends would run script when
 * rendered as an <a href>. A bare "facebook.com/shop" (no scheme) is allowed. Works on a single
 * string or, for map/list fields such as social_links, on every string inside the array.
 */
class SafeLink implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach ($this->strings($value) as $text) {
            $text = trim($text);
            if ($text !== '' && preg_match('/^[a-z][a-z0-9+.\-]*:/i', $text) && ! preg_match('#^https?://#i', $text)) {
                $fail('Links must start with http:// or https://.');

                return;
            }
        }
    }

    /** @return iterable<string> */
    private function strings(mixed $value): iterable
    {
        if (is_string($value)) {
            yield $value;
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                yield from $this->strings($item);
            }
        }
    }
}
