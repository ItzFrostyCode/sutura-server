<?php

return [
    // Where the daily database dump is kept. 'local' = this server's own disk (fine on a laptop, but wiped by
    // every redeploy on Railway). Point it at an off-server disk in production, e.g. BACKUP_DISK=s3 with the
    // Cloudflare R2 AWS_* variables set — the dump is uploaded there and the local copy removed.
    'disk' => env('BACKUP_DISK', 'local'),

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
];
