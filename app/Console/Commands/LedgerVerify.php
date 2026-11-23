<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Ledger\Actions\VerifyLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class LedgerVerify extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'Check that journals balance and stored balances match postings (money and points); read-only';

    public function handle(VerifyLedger $verify): int
    {
        $problems = $verify->handle();

        if ($problems === []) {
            $this->info('Ledger consistent.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }
        Log::critical('ledger:verify found problems', ['count' => count($problems), 'first' => array_slice($problems, 0, 10)]);

        return self::FAILURE;
    }
}
