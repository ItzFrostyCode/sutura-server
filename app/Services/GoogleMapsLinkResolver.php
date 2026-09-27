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

    /**
     * @return array{latitude: float, longitude: float}|null null when the
     *   URL isn't a recognized/allowed Google Maps link, or no coordinates
     *   could be found in it (or its resolved redirect target).
     */
    public static function resolve(string $url): ?array
    {
        $url = trim($url);
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host || ! in_array(strtolower($host), self::ALLOWED_HOSTS, true)) {
            return null;
        }

        // A short link (maps.app.goo.gl, goo.gl/maps/...) carries no
        // coordinates itself — they only appear in the full URL it
        // redirects to (…/@lat,lng,zoom/… or a …!3dlat!4dlng… data blob).
        // A direct google.com/maps URL usually already has them, but
        // resolving unconditionally is harmless (a no-redirect URL just
        // resolves to itself) and means one code path handles both.
        $finalUrl = $url;
        try {
            $response = Http::withOptions([
                'allow_redirects' => ['max' => 5, 'track_redirects' => true],
                'timeout' => 8,
            ])->get($url);

            $history = $response->header('X-Guzzle-Redirect-History');
            if ($history) {
                $hops = array_filter(array_map('trim', explode(',', $history)));
                if (! empty($hops)) {
                    $finalUrl = end($hops);
                }
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
