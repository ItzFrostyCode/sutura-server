<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class UpdateLastSeenAt
{
    /**
     * Handle an incoming request.
     * Updates the authenticated user's last_seen_at timestamp,
     * throttled to once per minute per user to reduce DB load.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (Auth::check()) {
            $user = Auth::user();
            $cacheKey = "last_seen_{$user->id}";

            // Only update DB once per minute per user
            if (!Cache::has($cacheKey)) {
                $user->timestamps = false; // Don't bump updated_at
                $user->last_seen_at = now();
                $user->save();

                Cache::put($cacheKey, true, 60); // 60 seconds TTL
            }
        }

        return $response;
    }
}
