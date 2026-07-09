<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Matches the thesis's own non-functional requirement: "execute automated
// database backups every 24 hours."
Schedule::command('app:backup-database')->daily();
