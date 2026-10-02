<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackupDatabase extends Command
{
    protected $signature = 'app:backup-database';

    protected $description = 'Back up the application database (sqlite/mysql/pgsql) and prune backups older than 30 days.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connection = config('database.default');
        $dbConfig = config("database.connections.{$connection}");

        $backupDir = storage_path('app/backups');
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = now()->format('Y-m-d_His');

        $ok = match ($connection) {
            'sqlite' => $this->backupSqlite($dbConfig, $backupDir, $timestamp),
            'mysql' => $this->backupMysql($dbConfig, $backupDir, $timestamp),
            'pgsql' => $this->backupPostgres($dbConfig, $backupDir, $timestamp),
            default => $this->failUnsupported($connection),
        };

        if ($ok) {
            $ok = $this->shipOffServer($backupDir);
        }
        $this->pruneOldBackups($backupDir);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function backupSqlite(array $config, string $backupDir, string $timestamp): bool
    {
        $sourcePath = $config['database'];
        $destPath = "{$backupDir}/backup-{$timestamp}.sqlite";

        if (! is_file($sourcePath) || ! copy($sourcePath, $destPath)) {
            $this->error('Failed to copy the SQLite database file.');

            return false;
        }

        $this->info("Database backed up to {$destPath}");

        return true;
    }

    private function backupMysql(array $config, string $backupDir, string $timestamp): bool
    {
        $destPath = "{$backupDir}/backup-{$timestamp}.sql";

        $command = sprintf(
            'mysqldump --user=%s --password=%s --host=%s --port=%s %s > %s 2>/dev/null',
            escapeshellarg($config['username'] ?? ''),
            escapeshellarg($config['password'] ?? ''),
            escapeshellarg($config['host'] ?? '127.0.0.1'),
            escapeshellarg((string) ($config['port'] ?? 3306)),
            escapeshellarg($config['database'] ?? ''),
            escapeshellarg($destPath)
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->error('mysqldump failed. Is the mysql-client installed on this server?');

            return false;
        }

        $this->info("Database backed up to {$destPath}");

        return true;
    }

    private function backupPostgres(array $config, string $backupDir, string $timestamp): bool
    {
        $destPath = "{$backupDir}/backup-{$timestamp}.sql";

        putenv('PGPASSWORD='.($config['password'] ?? ''));
        $command = sprintf(
            'pg_dump --username=%s --host=%s --port=%s %s > %s 2>/dev/null',
            escapeshellarg($config['username'] ?? ''),
            escapeshellarg($config['host'] ?? '127.0.0.1'),
            escapeshellarg((string) ($config['port'] ?? 5432)),
            escapeshellarg($config['database'] ?? ''),
            escapeshellarg($destPath)
        );
        exec($command, $output, $exitCode);
        putenv('PGPASSWORD');

        if ($exitCode !== 0) {
            $this->error('pg_dump failed. Is the postgresql-client installed on this server?');

            return false;
        }

        $this->info("Database backed up to {$destPath}");

        return true;
    }

    private function failUnsupported(string $connection): bool
    {
        $this->error("Unsupported database driver for backup: {$connection}");

        return false;
    }

    /**
     * Uploads today's dump to the configured off-server disk (BACKUP_DISK) and removes the local copy, so a
     * redeploy cannot take the only backup with it. With the default 'local' disk nothing is moved.
     */
    private function shipOffServer(string $backupDir): bool
    {
        $diskName = config('backup.disk', 'local');
        if ($diskName === 'local') {
            return true;
        }

        $files = glob("{$backupDir}/backup-*");
        if (! $files) {
            return true;
        }
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $latest = $files[0];

        try {
            $disk = Storage::disk($diskName);
            $disk->put('backups/'.basename($latest), fopen($latest, 'r'));
            $cutoff = now()->subDays((int) config('backup.retention_days', 30))->getTimestamp();
            foreach ($disk->files('backups') as $remote) {
                if ($disk->lastModified($remote) < $cutoff) {
                    $disk->delete($remote);
                }
            }
        } catch (\Throwable $e) {
            $this->error("Could not upload the backup to the '{$diskName}' disk: {$e->getMessage()} (the local copy was kept).");

            return false;
        }

        unlink($latest);
        $this->info("Backup uploaded to the '{$diskName}' disk: backups/".basename($latest));

        return true;
    }

    /**
     * Keeps disk usage bounded — daily backups accumulate forever otherwise.
     */
    private function pruneOldBackups(string $backupDir): void
    {
        $cutoff = now()->subDays((int) config('backup.retention_days', 30))->getTimestamp();
        foreach (glob("{$backupDir}/backup-*") as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }
}
