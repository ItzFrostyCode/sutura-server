<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:backup-database')]
#[Description('Back up the application database (sqlite/mysql/pgsql) and prune backups older than 30 days.')]
class BackupDatabase extends Command
{
    private const RETENTION_DAYS = 30;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connection = config('database.default');
        $dbConfig = config("database.connections.{$connection}");

        $backupDir = storage_path('app/backups');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = now()->format('Y-m-d_His');

        $ok = match ($connection) {
            'sqlite' => $this->backupSqlite($dbConfig, $backupDir, $timestamp),
            'mysql' => $this->backupMysql($dbConfig, $backupDir, $timestamp),
            'pgsql' => $this->backupPostgres($dbConfig, $backupDir, $timestamp),
            default => $this->failUnsupported($connection),
        };

        $this->pruneOldBackups($backupDir);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function backupSqlite(array $config, string $backupDir, string $timestamp): bool
    {
        $sourcePath = $config['database'];
        $destPath = "{$backupDir}/backup-{$timestamp}.sqlite";

        if (!is_file($sourcePath) || !copy($sourcePath, $destPath)) {
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

        putenv('PGPASSWORD=' . ($config['password'] ?? ''));
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
     * Keeps disk usage bounded — daily backups accumulate forever otherwise.
     */
    private function pruneOldBackups(string $backupDir): void
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS)->getTimestamp();
        foreach (glob("{$backupDir}/backup-*") as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }
}
