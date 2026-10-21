<?php

namespace App\Console\Commands;

use App\Support\DataSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Loads database/snapshot into this database without dropping it: runs the migrations, then REPLACES the rows of every
 * table in the snapshot (asks first; --force skips the question). `php artisan migrate:fresh --seed` does the same from
 * an empty database.
 */
class SnapshotImport extends Command
{
    protected $signature = 'app:snapshot-import {--force : Do not ask before replacing the data}';

    protected $description = 'Replace this database and uploaded files with database/snapshot.';

    public function handle(): int
    {
        if (! DataSnapshot::exists()) {
            $this->error('No snapshot in database/snapshot. Run app:snapshot-export on the computer that has the data.');

            return self::FAILURE;
        }
        $db = config('database.connections.'.config('database.default').'.database');
        if (! $this->option('force') && ! $this->confirm("This replaces the data in {$db} with the snapshot. Continue?")) {
            return self::FAILURE;
        }

        Artisan::call('migrate', ['--force' => true]);
        $r = DataSnapshot::import();

        foreach ($r['skipped'] as $t) {
            $this->warn("Skipped {$t}: this database has no such table (git pull, then migrate).");
        }
        $this->info("Loaded {$r['rows']} rows and {$r['files']} files.".($r['moved_links'] ? " Links moved: {$r['moved_links']}." : ''));
        $this->line('Sign in again on this computer: sign-in sessions are never copied.');

        return self::SUCCESS;
    }
}
