<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Speed check (ADR-035): renders pages in-process (as Inertia visits, no SSR) twice and prints status, query count,
 * database time and total time, plus any query repeated more than twice (a likely N+1). Guest requests.
 */
final class PerfPages extends Command
{
    protected $signature = 'perf:pages {paths?* : Paths to measure (defaults to the busiest public pages)}';

    protected $description = 'Queries and time per page, with repeated-query hints';

    private const array DEFAULT = ['/en', '/en/games', '/en/catalog/accounts', '/en/search?q=steam'];

    public function handle(Kernel $kernel): int
    {
        /** @var list<string> $paths */
        $paths = $this->argument('paths') ?: self::DEFAULT;
        $version = app(HandleInertiaRequests::class)->version(Request::create('/'));
        $failed = false;

        foreach ($paths as $path) {
            foreach ([1, 2] as $run) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $started = microtime(true);
                $request = Request::create($path, 'GET', server: ['HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => (string) $version]);
                $response = $kernel->handle($request);
                $total = (microtime(true) - $started) * 1000;
                $log = DB::getQueryLog();
                $kernel->terminate($request, $response);

                $this->line(sprintf('%-32s %s  status %d  queries %3d  db %6.1f ms  total %6.1f ms', $path, $run === 1 ? 'cold' : 'warm', $response->getStatusCode(), count($log), array_sum(array_column($log, 'time')), $total));
                $failed = $failed || $response->getStatusCode() >= 500;

                if ($run === 2) {
                    $counts = [];
                    foreach ($log as $query) {
                        $shape = (string) preg_replace('/\d+/', 'N', (string) $query['query']);
                        $counts[$shape] = ($counts[$shape] ?? 0) + 1;
                    }
                    arsort($counts);
                    foreach ($counts as $shape => $n) {
                        if ($n > 2) {
                            $this->warn(sprintf('    repeated x%d: %s', $n, mb_substr($shape, 0, 140)));
                        }
                    }
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
