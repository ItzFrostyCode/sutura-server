<?php

namespace App\Console\Commands;

use App\Support\DataSnapshot;
use Illuminate\Console\Command;

/** Saves all data and uploaded files into database/snapshot, so another computer (or `migrate:fresh --seed`) gets the same. */
class SnapshotExport extends Command
{
    protected $signature = 'app:snapshot-export';

    protected $description = 'Save all data and uploaded files into database/snapshot (used by db:seed and app:snapshot-import).';

    public function handle(): int
    {
        $r = DataSnapshot::export();
        $this->info("Saved {$r['rows']} rows from {$r['tables']} tables and {$r['files']} files to database/snapshot.");
        $this->line('Another computer: git pull, composer install, then php artisan migrate:fresh --seed (or app:snapshot-import).');

        return self::SUCCESS;
    }
}
