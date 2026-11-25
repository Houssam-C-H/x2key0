<?php

declare(strict_types=1);

namespace App\Shared\Ops;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PharData;
use RuntimeException;

/**
 * Nightly backup (docs/RUNBOOK.md §4), on top of the provider's point-in-time recovery: a database dump
 * (PostgreSQL custom format via pg_dump, or an SQLite copy via VACUUM INTO) and an archive of uploaded files
 * (storage/app/public and private), each with its SHA-256, plus a manifest, copied to `x2key.ops.backup_disk`
 * under backups/<UTC stamp>/. Older sets beyond the retention are deleted. The last success is remembered for the
 * health check. Secrets in the dump stay encrypted (sealed columns) exactly as in the database.
 */
final class Backup
{
    public const string LAST_KEY = 'ops:last-backup';

    /**
     * @return array{stamp: string, files: array<string, array{bytes: int, sha256: string}>, pruned: int}
     */
    public function run(): array
    {
        $stamp = now()->utc()->format('Ymd\THis\Z');
        $work = storage_path('app/backup-work/'.$stamp);
        File::ensureDirectoryExists($work);

        try {
            $files = [];
            $files[] = $this->dumpDatabase($work);
            $uploads = $this->archiveUploads($work);
            if ($uploads !== null) {
                $files[] = $uploads;
            }

            $disk = Storage::disk(config()->string('x2key.ops.backup_disk'));
            $manifest = [];
            foreach ($files as $path) {
                $name = basename($path);
                $stream = fopen($path, 'rb');
                if ($stream === false) {
                    throw new RuntimeException("cannot read {$name}");
                }
                $disk->writeStream("backups/{$stamp}/{$name}", $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $manifest[$name] = ['bytes' => (int) filesize($path), 'sha256' => (string) hash_file('sha256', $path)];
            }
            $disk->put("backups/{$stamp}/manifest.json", (string) json_encode([
                'stamp' => $stamp,
                'app' => config('app.name'),
                'database' => DB::getDriverName(),
                'files' => $manifest,
            ], JSON_PRETTY_PRINT));

            $pruned = $this->prune();
            Cache::forever(self::LAST_KEY, ['at' => now()->getTimestamp(), 'stamp' => $stamp]);

            return ['stamp' => $stamp, 'files' => $manifest, 'pruned' => $pruned];
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** Seconds since the last good backup, or null when none is known. */
    public static function age(): ?int
    {
        $last = Cache::get(self::LAST_KEY);

        return is_array($last) && is_int($last['at'] ?? null) ? now()->getTimestamp() - $last['at'] : null;
    }

    private function dumpDatabase(string $work): string
    {
        $c = 'database.connections.'.config()->string('database.default');
        $setting = static fn (string $key, string $default = ''): string => is_scalar($v = config("{$c}.{$key}")) ? (string) $v : $default;

        if (DB::getDriverName() === 'sqlite') {
            // VACUUM INTO gives an exact copy but cannot run inside a transaction; then write a plain SQL dump.
            if (DB::transactionLevel() === 0) {
                $file = $work.'/database.sqlite';
                DB::statement('VACUUM INTO ?', [$file]);

                return $file;
            }

            return $this->sqliteSqlDump($work.'/database.sql');
        }

        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('ops:backup supports PostgreSQL and SQLite.');
        }

        $file = $work.'/database.dump';
        $result = Process::timeout(3600)->env(['PGPASSWORD' => $setting('password')])->run([
            'pg_dump', '--format=custom', '--compress=9', '--no-owner', '--no-privileges',
            '--host='.$setting('host', '127.0.0.1'),
            '--port='.$setting('port', '5432'),
            '--username='.$setting('username'),
            '--file='.$file,
            $setting('database'),
        ]);
        if (! $result->successful()) {
            throw new RuntimeException('pg_dump failed: '.mb_substr($result->errorOutput(), 0, 300));
        }

        return $file;
    }

    /** Schema + rows of every SQLite table as SQL statements (restorable with the sqlite3 shell). */
    private function sqliteSqlDump(string $file): string
    {
        $out = fopen($file, 'wb');
        if ($out === false) {
            throw new RuntimeException('cannot write the SQL dump');
        }
        $pdo = DB::connection()->getPdo();
        fwrite($out, "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n");
        foreach (DB::select("select name, sql from sqlite_master where type = 'table' and name not like 'sqlite_%' and sql is not null") as $table) {
            if (! $table instanceof \stdClass) {
                continue;
            }
            $name = is_string($table->name ?? null) ? $table->name : '';
            fwrite($out, (is_string($table->sql ?? null) ? $table->sql : '').";\n");
            foreach (DB::table($name)->cursor() as $row) {
                $values = array_map(static fn (mixed $v): string => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote(is_scalar($v) ? (string) $v : '')), (array) $row);
                fwrite($out, 'INSERT INTO "'.$name.'" VALUES('.implode(',', $values).");\n");
            }
        }
        foreach (DB::select("select sql from sqlite_master where type = 'index' and sql is not null") as $index) {
            if (! $index instanceof \stdClass) {
                continue;
            }
            fwrite($out, (is_string($index->sql ?? null) ? $index->sql : '').";\n");
        }
        fwrite($out, "COMMIT;\n");
        fclose($out);

        return $file;
    }

    /** storage/app/public + private as uploads.tar.gz (null when there are no uploads). */
    private function archiveUploads(string $work): ?string
    {
        $roots = array_filter([storage_path('app/public'), storage_path('app/private')], is_dir(...));
        if ($roots === []) {
            return null;
        }

        $tar = $work.'/uploads.tar';
        $archive = new PharData($tar);
        foreach ($roots as $root) {
            $prefix = basename($root).'/';
            foreach (File::allFiles($root) as $file) {
                $archive->addFile($file->getPathname(), $prefix.str_replace('\\', '/', $file->getRelativePathname()));
            }
        }
        if ($archive->count() === 0) {
            return null;
        }
        $archive->compress(\Phar::GZ);
        unset($archive);
        @unlink($tar);

        return $tar.'.gz';
    }

    private function prune(): int
    {
        $disk = Storage::disk(config()->string('x2key.ops.backup_disk'));
        $cutoff = now()->utc()->subDays(max(1, config()->integer('x2key.ops.backup_retention_days')));
        $pruned = 0;

        foreach ($disk->directories('backups') as $dir) {
            $stamp = basename($dir);
            $at = Carbon::createFromFormat('Ymd\THis\Z', $stamp, 'UTC');
            if ($at !== null && $at->lt($cutoff)) {
                $disk->deleteDirectory($dir);
                $pruned++;
            }
        }

        return $pruned;
    }
}
