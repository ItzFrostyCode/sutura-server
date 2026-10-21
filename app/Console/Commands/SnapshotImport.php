<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Loads what app:snapshot-export saved, so this computer has the same data and uploaded files. It REPLACES the rows of
 * every table in the snapshot (it asks first; --force skips the question). Links in the data that point at the other
 * computer's address (APP_URL) are rewritten to this computer's. Works the same on Mac, Windows and Linux.
 */
class SnapshotImport extends Command
{
    protected $signature = 'app:snapshot-import {--path=database/snapshot : Where the snapshot is} {--force : Do not ask before replacing the data}';

    protected $description = 'Replace this database and uploaded files with the snapshot in database/snapshot.';

    public function handle(): int
    {
        $root = base_path($this->option('path'));
        $manifestPath = $root.'/manifest.json';
        if (! is_file($manifestPath)) {
            $this->error("No snapshot found at {$this->option('path')}. Run app:snapshot-export on the computer that has the data.");

            return self::FAILURE;
        }
        $manifest = json_decode(File::get($manifestPath), true);

        if (! $this->option('force') && ! $this->confirm('This replaces the data in this database ('.config('database.connections.'.config('database.default').'.database').') with the snapshot from '.$manifest['created_at'].'. Continue?')) {
            return self::FAILURE;
        }

        // The tables must exist and match: bring this database's structure up to date first.
        Artisan::call('migrate', ['--force' => true]);
        $this->line(trim(Artisan::output()) ?: 'Migrations are up to date.');

        $from = rtrim((string) ($manifest['app_url'] ?? ''), '/');
        $to = rtrim((string) config('app.url'), '/');
        $rewrite = $from !== '' && $to !== '' && $from !== $to;

        $driver = DB::connection()->getDriverName();
        Schema::disableForeignKeyConstraints();
        $rows = 0;
        try {
            foreach (array_keys($manifest['tables']) as $table) {
                if (! Schema::hasTable($table)) {
                    $this->warn("Skipped {$table}: this database has no such table (run git pull and migrate).");

                    continue;
                }
                $data = json_decode(File::get("{$root}/data/{$table}.json"), true) ?? [];
                $columns = array_flip(Schema::getColumnListing($table));
                DB::table($table)->delete();
                foreach (array_chunk($data, 300) as $chunk) {
                    $chunk = array_map(function (array $row) use ($columns, $rewrite, $from, $to) {
                        // Columns this database does not have (an older migration) are dropped instead of failing.
                        $row = array_intersect_key($row, $columns);
                        if ($rewrite) {
                            foreach ($row as $k => $v) {
                                if (is_string($v) && str_contains($v, $from)) {
                                    $row[$k] = str_replace([$from, str_replace('/', '\\/', $from)], [$to, str_replace('/', '\\/', $to)], $v);
                                }
                            }
                        }

                        return $row;
                    }, $chunk);
                    DB::table($table)->insert($chunk);
                    $rows += count($chunk);
                }
                // Postgres keeps its own id counters: move them past the loaded rows so new records do not collide.
                if ($driver === 'pgsql' && isset($columns['id']) && count($data) > 0) {
                    DB::statement("SELECT setval(pg_get_serial_sequence('\"{$table}\"', 'id'), (SELECT COALESCE(MAX(id), 1) FROM \"{$table}\"))");
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        // Uploaded files back where the links expect them, and the public/storage link that serves them.
        $files = 0;
        if (is_dir($root.'/storage')) {
            File::ensureDirectoryExists(storage_path('app/public'));
            File::copyDirectory($root.'/storage', storage_path('app/public'));
            $files = count(File::allFiles($root.'/storage'));
        }
        if (! file_exists(public_path('storage'))) {
            Artisan::call('storage:link');
        }

        $this->info("Loaded {$rows} rows and {$files} files.".($rewrite ? " Links moved from {$from} to {$to}." : ''));
        $this->line('Sign in again on this computer: sign-in sessions are never copied.');

        return self::SUCCESS;
    }
}
