<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turns whatever a shop owner pastes from Google Maps' own "Share" button
 * (a short maps.app.goo.gl link, or a full google.com/maps URL copied from
 * the address bar) into a plain lat/lng pair — so a non-technical owner
 * never has to go find raw coordinates themselves. Raw lat/lng entry stays
 * available as a fallback/override for anyone who already has them.
 */
class GoogleMapsLinkResolver
{
    /**
     * Hosts we'll actually fetch. Anything else is rejected before any
     * outbound request is made — this endpoint takes an arbitrary
     * user-supplied URL, so an open allowlist would be a same-request SSRF
     * vector (the server fetching an internal/attacker-chosen address).
     */
    private const ALLOWED_HOSTS = [
        'maps.app.goo.gl',
        'goo.gl',
        'google.com',
        'www.google.com',
        'maps.google.com',
    ];

    private const MAX_REDIRECTS = 5;

    /** https only, on an allowed host, no embedded credentials or custom port. */
    private static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (! $parts || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return in_array(strtolower($parts['host'] ?? ''), self::ALLOWED_HOSTS, true);
    }

    /** Resolves a Location header (absolute, scheme-relative or path-only) against the URL it came from. */
    private static function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$location;
        }

        return $origin.(str_starts_with($location, '/') ? '' : '/').$location;
    }

    /**
     * @return array{latitude: float, longitude: float}|null null when the
     *   URL isn't a recognized/allowed Google Maps link, or no coordinates
     *   could be found in it (or its resolved redirect target).
     */
    public static function resolve(string $url): ?array
    {
        $url = trim($url);
        if (! self::isAllowed($url)) {
            return null;
        }

        // A short link (maps.app.goo.gl, goo.gl/maps/...) carries no
        // coordinates itself — they only appear in the full URL it
        // redirects to (…/@lat,lng,zoom/… or a …!3dlat!4dlng… data blob).
        //
        // Redirects are followed by hand, never automatically: an allowlisted host
        // (google.com has open redirects) could otherwise bounce the server to an
        // internal or attacker-chosen address. Every hop must itself be an allowed
        // https Google host, or the chain stops there.
        $finalUrl = $url;
        try {
            $current = $url;
            for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
                $response = Http::withoutRedirecting()->timeout(8)->get($current);
                $location = $response->redirect() ? $response->header('Location') : null;
                if (! $location) {
                    break;
                }
                $next = self::absolute($current, $location);
                if (! self::isAllowed($next)) {
                    break;
                }
                $current = $finalUrl = $next;
            }
        } catch (\Throwable $e) {
            // Network hiccup or the link no longer resolves — fall back to
            // parsing the pasted URL as-is; a direct (non-short) Google Maps
            // URL often already has coordinates in it even unresolved.
            Log::warning('GoogleMapsLinkResolver: failed to resolve link', ['url' => $url, 'error' => $e->getMessage()]);
        }

        return self::extractLatLng($finalUrl) ?? self::extractLatLng($url);
    }

    /**
     * Tries the coordinate patterns Google Maps URLs actually use, most
     * precise first: the "!3d{lat}!4d{lng}" pin-marker data blob (present
     * on a specific-place link), then "@{lat},{lng},{zoom}z" (the map
     * viewport center — usually the pin too, but not guaranteed on every
     * link shape), then the plain "q="/"ll=" query-param forms older or
     * simpler share links use.
     */
    private static function extractLatLng(string $url): ?array
    {
        $patterns = [
            '/!3d(-?\d{1,3}\.\d+)!4d(-?\d{1,3}\.\d+)/',
            '/@(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)/',
            '/[?&]q=(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)/',
            '/[?&]ll=(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
                if (abs($lat) <= 90 && abs($lng) <= 180) {
                    return ['latitude' => $lat, 'longitude' => $lng];
                }
            }
        }

        return null;
    }
}
