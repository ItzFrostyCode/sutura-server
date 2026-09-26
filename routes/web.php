<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Resilient direct static asset streaming from storage/app/public.
// Guarantees store logos, banners, receipts, and user uploads are served across macOS and Windows
// even when `php artisan storage:link` has not been run or symlinks are unsupported.
Route::get('/storage/{path}', function (string $path) {
    $cleanPath = str_replace(['..', "\0"], '', $path);
    $filePath = storage_path('app/public/' . $cleanPath);

    if (! file_exists($filePath) || is_dir($filePath)) {
        abort(404);
    }

    $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

    return response()->file($filePath, [
        'Content-Type' => $mimeType,
        'Access-Control-Allow-Origin' => '*',
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->where('path', '.*');
