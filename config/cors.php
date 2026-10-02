<?php

// The API is called from the Next.js app with a Bearer token (no cookies). In production set
// FRONTEND_URL (and CORS_ALLOWED_ORIGINS for extras, comma-separated) on the server.
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    // Production: only the configured frontend. Local/dev: any origin, so testing from a phone on the
    // LAN (http://192.168.x.x:3000) keeps working — requests carry a Bearer token, not cookies.
    'allowed_origins' => env('APP_ENV', 'production') === 'production'
        ? array_values(array_filter(array_unique(array_merge(
            [rtrim((string) env('FRONTEND_URL', ''), '/')],
            array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
        ))))
        : ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With'],
    // Content-Disposition carries the download's file name; without this a browser on another origin cannot read it.
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 600,
    'supports_credentials' => false,
];
