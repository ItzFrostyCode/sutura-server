<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * The app's data as files: every table as JSON (database/snapshot/data/{table}.json) plus the uploaded files
 * (storage/app/public → database/snapshot/storage) and a manifest. Plain PHP (no mysqldump), so it works on macOS,
 * Windows and Linux, with MySQL or Postgres. Used by app:snapshot-export / app:snapshot-import and by SnapshotSeeder
 * (so `php artisan migrate:fresh --seed` gives back the same data). Sign-in tokens, sessions, caches and queues are
 * never copied: they belong to one computer.
 */
class DataSnapshot
{
    public const SKIP = ['migrations', 'personal_access_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    public static function path(): string
    {
        return database_path('snapshot');
    }

    public static function exists(?string $root = null): bool
    {
        return is_file(($root ?? self::path()).'/manifest.json');
    }

    /** @return array{rows:int, tables:int, files:int} */
    public static function export(?string $root = null): array
    {
        $root ??= self::path();
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
        $files = 0;
        $source = storage_path('app/public');
        if (is_dir($source)) {
            File::copyDirectory($source, $filesDir);
            // storage/app/public carries a ".gitignore" that ignores everything; copied along, git would skip the files.
            foreach (File::allFiles($filesDir, true) as $f) {
                if ($f->getFilename() === '.gitignore') {
                    File::delete($f->getPathname());
                }
            }
            $files = count(File::allFiles($filesDir));
        }

        File::put($root.'/manifest.json', json_encode([
            'created_at' => now()->toIso8601String(),
            'app_url' => rtrim((string) config('app.url'), '/'),
            'driver' => DB::connection()->getDriverName(),
            'last_migration' => DB::table('migrations')->orderByDesc('id')->value('migration'),
            'tables' => $counts,
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return ['rows' => array_sum($counts), 'tables' => count($counts), 'files' => $files];
    }

    /**
     * Replaces the rows of every table in the snapshot (the tables must already exist: run the migrations first), copies
     * the files back and links public/storage. Links that pointed at the snapshot computer's APP_URL are rewritten to
     * this one's.
     *
     * @return array{rows:int, files:int, skipped:string[], moved_links:?string}
     */
    public static function import(?string $root = null): array
    {
        $root ??= self::path();
        $manifest = json_decode(File::get($root.'/manifest.json'), true);

        $from = rtrim((string) ($manifest['app_url'] ?? ''), '/');
        $to = rtrim((string) config('app.url'), '/');
        $rewrite = $from !== '' && $to !== '' && $from !== $to;

        $driver = DB::connection()->getDriverName();
        $rows = 0;
        $skipped = [];
        // Postgres enforces FKs at insert time (no FOREIGN_KEY_CHECKS switch), so for the load
        // we drop every public FK constraint and re-add it afterwards; the other drivers just
        // disable checks for the session.
        $restoreFks = $driver === 'pgsql' ? self::dropPostgresForeignKeys() : null;
        if ($restoreFks === null) {
            Schema::disableForeignKeyConstraints();
        }

        try {
            foreach (self::importOrder($manifest['tables']) as $table) {
                if (! Schema::hasTable($table)) {
                    $skipped[] = $table;

                    continue;
                }
                $data = json_decode(File::get("{$root}/data/{$table}.json"), true) ?? [];
                $columns = array_flip(Schema::getColumnListing($table));
                DB::table($table)->delete();
                foreach (array_chunk($data, 300) as $chunk) {
                    $chunk = array_map(fn (array $row) => self::prepare($row, $columns, $rewrite ? [$from, $to] : null), $chunk);
                    DB::table($table)->insert($chunk);
                    $rows += count($chunk);
                }
                // Postgres keeps its own id counters: move them past the loaded rows so new records do not collide.
                // Only integer serial PKs have a sequence — a UUID primary key (e.g. `notifications`) has none, and
                // MAX() over a uuid column does not exist on Postgres, so skip the bump when there is no sequence.
                if ($driver === 'pgsql' && isset($columns['id']) && count($data) > 0) {
                    $seq = DB::selectOne("SELECT pg_get_serial_sequence('\"{$table}\"', 'id') AS seq")->seq;
                    if ($seq !== null) {
                        $maxId = (int) DB::table($table)->max('id');
                        DB::selectOne('SELECT setval(?, ?)', [$seq, max($maxId, 1)]);
                    }
                }
            }
        } finally {
            if ($restoreFks !== null) {
                self::restorePostgresForeignKeys($restoreFks);
            } else {
                Schema::enableForeignKeyConstraints();
            }
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

        return ['rows' => $rows, 'files' => $files, 'skipped' => $skipped, 'moved_links' => $rewrite ? "{$from} → {$to}" : null];
    }

    /**
     * The snapshot's tables in an order that is safe on every driver: a table is imported only
     * after the tables its rows reference. MySQL and SQLite tolerate any order because the import
     * disables FK checks; Postgres enforces them at insert time, so children must follow parents.
     * Without this, `appointment_items` (which sorts before `appointments`) lands before its parent
     * and Postgres rejects it with a foreign-key violation.
     *
     * @param array<string, int> $tables  table name => row count, from the manifest
     * @return string[]
     */
    private static function importOrder(array $tables): array
    {
        $want = array_flip(array_keys($tables));
        $deps = [];
        foreach (array_keys($tables) as $table) {
            $parents = [];
            if (Schema::hasTable($table)) {
                try {
                    foreach (Schema::getForeignKeys($table) as $fk) {
                        $parent = $fk['foreign_table'] ?? null;
                        if ($parent !== null && $parent !== $table && isset($want[$parent])) {
                            $parents[] = $parent;
                        }
                    }
                } catch (\Throwable) {
                    // This driver does not expose FK metadata; the table just keeps no known order.
                }
            }
            $deps[$table] = array_values(array_unique($parents));
        }

        return self::topologicalOrder(array_keys($tables), $deps);
    }

    /**
     * Parents-before-children ordering. Any table left unplaced (a cycle, or FKs pointing at a
     * table that is not part of this snapshot) is appended alphabetically so no table is dropped.
     *
     * @param string[]       $tables
     * @param array<string, string[]> $deps  table => parent tables it references
     * @return string[]
     */
    private static function topologicalOrder(array $tables, array $deps): array
    {
        $pending = [];
        $children = [];
        foreach ($tables as $table) {
            $parents = $deps[$table] ?? [];
            $pending[$table] = count($parents);
            foreach ($parents as $parent) {
                $children[$parent][] = $table;
            }
        }

        $queue = array_values(array_filter($tables, fn (string $t) => ($pending[$t] ?? 0) === 0));
        $order = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            $order[] = $current;
            foreach ($children[$current] ?? [] as $child) {
                if (isset($pending[$child]) && --$pending[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }

        $leftover = array_values(array_diff($tables, $order));
        sort($leftover);

        return array_merge($order, $leftover);
    }

    /**
     * Drop every FK in the public schema and return the DDL to re-add each one. `pg_get_constraintdef`
     * omits the constraint name, so we capture `conname` separately and re-add as
     * `ADD CONSTRAINT <name> <def>`. The schema must already match (migrations ran first);
     * re-adding also validates the loaded rows against the constraints, so a corrupt snapshot fails
     * loudly here instead of leaving orphaned rows behind.
     *
     * @return string[]
     */
    private static function dropPostgresForeignKeys(): array
    {
        $restore = [];
        $rows = DB::select(
            "SELECT format('%I.%I', n.nspname, t.relname) AS tbl, c.conname, pg_get_constraintdef(c.oid) AS def
               FROM pg_constraint c
               JOIN pg_namespace n ON n.oid = c.connamespace
               JOIN pg_class t ON t.oid = c.conrelid
              WHERE c.contype = 'f' AND n.nspname = 'public'"
        );
        foreach ($rows as $row) {
            DB::statement("ALTER TABLE {$row->tbl} DROP CONSTRAINT \"{$row->conname}\"");
            $restore[] = "ALTER TABLE {$row->tbl} ADD CONSTRAINT \"{$row->conname}\" {$row->def}";
        }

        return $restore;
    }

    /** Re-add the FKs that {@see dropPostgresForeignKeys()} removed. */
    private static function restorePostgresForeignKeys(array $restore): void
    {
        foreach ($restore as $ddl) {
            DB::statement($ddl);
        }
    }

    /** Keeps only the columns this database has (an older or newer migration is not fatal) and moves links to this APP_URL. */
    private static function prepare(array $row, array $columns, ?array $move): array
    {
        $row = array_intersect_key($row, $columns);
        if ($move) {
            [$from, $to] = $move;
            foreach ($row as $k => $v) {
                if (is_string($v) && str_contains($v, $from)) {
                    $row[$k] = str_replace([$from, str_replace('/', '\\/', $from)], [$to, str_replace('/', '\\/', $to)], $v);
                }
            }
        }

        return $row;
    }
}
