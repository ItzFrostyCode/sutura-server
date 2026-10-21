<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Saves the whole app's data so another computer (Mac or Windows) can have exactly the same: every table as JSON
 * (database/snapshot/data/{table}.json) plus the uploaded files (storage/app/public → database/snapshot/storage).
 * Plain PHP, no mysqldump, so it works on any machine and with MySQL or Postgres. Restore with app:snapshot-import.
 * Sign-in tokens, sessions, caches and queues are left out: they belong to this computer only.
 */
class SnapshotExport extends Command
{
    protected $signature = 'app:snapshot-export {--path=database/snapshot : Where to write the snapshot}';

    protected $description = 'Save all data and uploaded files into database/snapshot (for another machine).';

    /** Per-machine or secret: never copied. */
    public const SKIP = ['migrations', 'personal_access_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    public function handle(): int
    {
        $root = base_path($this->option('path'));
        $dataDir = $root.'/data';
        $filesDir = $root.'/storage';
        File::deleteDirectory($dataDir);
        File::deleteDirectory($filesDir);
        File::ensureDirectoryExists($dataDir);

        $tables = collect(Schema::getTableListing())
            ->map(fn ($t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t)
            ->reject(fn ($t) => in_array($t, self::SKIP, true))
            ->sort()->values();

        $counts = [];
        foreach ($tables as $table) {
            $query = DB::table($table);
            if (Schema::hasColumn($table, 'id')) {
                $query->orderBy('id');
            }
            $rows = $query->get()->map(fn ($r) => (array) $r)->all();
            File::put("{$dataDir}/{$table}.json", json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $counts[$table] = count($rows);
        }

        // Uploaded files (photos, receipts, logos): the same paths, so every URL in the data still points at them.
        $source = storage_path('app/public');
        $fileCount = 0;
        if (is_dir($source)) {
            File::copyDirectory($source, $filesDir);
            // storage/app/public carries a ".gitignore" that ignores everything; copied along, git would skip the files.
            foreach (File::allFiles($filesDir, true) as $f) {
                if ($f->getFilename() === '.gitignore') {
                    File::delete($f->getPathname());
                }
            }
            $fileCount = count(File::allFiles($filesDir));
        }

        File::put($root.'/manifest.json', json_encode([
            'created_at' => now()->toIso8601String(),
            'app_url' => rtrim((string) config('app.url'), '/'),
            'driver' => DB::connection()->getDriverName(),
            'last_migration' => DB::table('migrations')->orderByDesc('id')->value('migration'),
            'tables' => $counts,
            'files' => $fileCount,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->info('Saved '.array_sum($counts).' rows from '.count($counts)." tables and {$fileCount} files to {$this->option('path')}.");
        $this->line('On the other computer: git pull, composer install, php artisan migrate, then php artisan app:snapshot-import');

        return self::SUCCESS;
    }
}
