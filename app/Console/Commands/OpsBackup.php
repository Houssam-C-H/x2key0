<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Ops\Backup;
use Illuminate\Console\Command;
use Throwable;

/** `ops:backup` (nightly): database dump + uploads to the backup disk, retention, last-success marker. */
final class OpsBackup extends Command
{
    protected $signature = 'ops:backup';

    protected $description = 'Back up the database and uploaded files to the backup disk (docs/RUNBOOK.md §4)';

    public function handle(Backup $backup): int
    {
        try {
            $result = $backup->run();
        } catch (Throwable $e) {
            report($e);
            $this->error('backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['files'] as $name => $file) {
            $this->line(sprintf('%-20s %10s bytes  sha256 %s', $name, number_format($file['bytes']), $file['sha256']));
        }
        $this->info("backup {$result['stamp']} stored; {$result['pruned']} old set(s) removed");

        return self::SUCCESS;
    }
}
