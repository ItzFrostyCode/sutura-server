<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role'      => \App\Http\Middleware\CheckRole::class,
            'last.seen' => \App\Http\Middleware\UpdateLastSeenAt::class,
        ]);

        // Stamp last_seen_at on every authenticated API request
        $middleware->appendToGroup('api', \App\Http\Middleware\UpdateLastSeenAt::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // With APP_DEBUG=true (the local default), any unhandled exception —
        // a bad route-model-binding id, a wrong HTTP verb, a raw DB error —
        // rendered a full stack trace with server file paths straight into
        // the JSON response. Found via live testing: a plain 404 (job order
        // that doesn't exist) and a 405 (no GET route for a single
        // appointment) both leaked one. ValidationException/Authentication/
        // AuthorizationException are left alone — Laravel's own default
        // rendering for those is already clean and the frontend depends on
        // ValidationException's field-level `errors` shape specifically.
        // Everything else gets a short message and the right status instead
        // of a trace, regardless of debug mode.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            if ($e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException
                || $e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                return null;
            }

            $status = match (true) {
                $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException,
                $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException => 404,
                $e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException => 405,
                $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };

            $message = match ($status) {
                404 => 'The requested resource was not found.',
                405 => 'This action is not supported for this endpoint.',
                default => 'Something went wrong. Please try again.',
            };

            return response()->json(['success' => false, 'message' => $message], $status);
        });
    })->create();
