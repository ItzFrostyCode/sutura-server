<?php

namespace Database\Seeders;

use App\Support\DataSnapshot;
use Illuminate\Database\Seeder;

/**
 * The saved data (database/snapshot, made with `php artisan app:snapshot-export`): every shop, account, appointment,
 * order and uploaded photo, exactly as they were. `php artisan migrate:fresh --seed` runs this.
 */
class SnapshotSeeder extends Seeder
{
    public function run(): void
    {
        $r = DataSnapshot::import();
        foreach ($r['skipped'] as $t) {
            $this->command?->warn("Skipped {$t}: no such table in this database.");
        }
        $this->command?->info("Snapshot loaded: {$r['rows']} rows, {$r['files']} files.".($r['moved_links'] ? " Links moved: {$r['moved_links']}." : ''));
    }
}
