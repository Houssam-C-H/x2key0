<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Invoices\Actions\IssueInvoices;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class InvoicesIssueMonthly extends Command
{
    protected $signature = 'invoices:issue-monthly {--month= : YYYY-MM, default last month}';

    protected $description = 'Issue the monthly service invoices (platform fees) for every seller (idempotent)';

    public function handle(IssueInvoices $issue): int
    {
        $option = $this->option('month');
        $month = is_string($option) && preg_match('/^\d{4}-\d{2}$/', $option) === 1 ? Carbon::createFromFormat('Y-m-d', "{$option}-01") : null;

        $this->info('Issued '.$issue->monthly($month instanceof Carbon ? $month : null).' service invoice(s).');

        return self::SUCCESS;
    }
}
