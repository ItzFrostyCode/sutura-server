<?php

namespace App\Http\Middleware;

use App\Support\ActivityRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes one Activity Log row per successful change (see ActivityRecorder).
 * Runs after the response is sent, so logging never slows anyone down.
 */
class RecordActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        ActivityRecorder::fromRequest($request, $response);
    }
}
