<?php

return [
    // Where the daily database dump is kept. 'local' = storage/app/backups.
    'disk' => env('BACKUP_DISK', 'local'),

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
];
