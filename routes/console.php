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

// Turns the passive overdue_jobs dashboard KPI into a proactive daily alert —
// most useful exactly when an owner is too busy (peak season) to remember to
// check the dashboard themselves.
Schedule::command('app:notify-overdue-jobs')->dailyAt('08:00');
