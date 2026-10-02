<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Turns whatever is stored in a receipt column (a "/storage/stores/1/receipts/x.jpg" path, a full URL,
 * or later a cloud-bucket URL) into the actual bytes, so receipts can be bundled into a download.
 */
class ReceiptFiles
{
    /** @return array{absolute?: string, contents?: string, ext: string}|null  null when the file cannot be found */
    public static function locate(?string $stored): ?array
    {
        if (! $stored) {
            return null;
        }
        $path = parse_url($stored, PHP_URL_PATH) ?: $stored;
        $path = rawurldecode($path);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'jpg';

        // Local public disk: /storage/<key>
        if (preg_match('#^/?storage/(.+)$#', $path, $m) && Storage::disk('public')->exists($m[1])) {
            return ['absolute' => Storage::disk('public')->path($m[1]), 'ext' => $ext];
        }
        $key = ltrim($path, '/');
        if ($key !== '' && Storage::disk('public')->exists($key)) {
            return ['absolute' => Storage::disk('public')->path($key), 'ext' => $ext];
        }
        if ($key !== '' && is_file(public_path($key))) {
            return ['absolute' => public_path($key), 'ext' => $ext];
        }

        // Off-server bucket (S3 / Cloudflare R2): try the key as given, then without a leading bucket name.
        if (config('filesystems.disks.s3.bucket')) {
            $bucket = (string) config('filesystems.disks.s3.bucket');
            foreach ([$key, preg_replace('#^'.preg_quote($bucket, '#').'/#', '', $key)] as $candidate) {
                try {
                    if ($candidate && Storage::disk('s3')->exists($candidate)) {
                        return ['contents' => Storage::disk('s3')->get($candidate), 'ext' => $ext];
                    }
                } catch (\Throwable) {
                    // unreachable bucket — treated as "file not found" for this receipt
                }
            }
        }

        return null;
    }
}
