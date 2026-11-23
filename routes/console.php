<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs (spec Part 19). Each milestone adds its own.
*/

Schedule::command('catalog:recount')->everyTenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('offers:expire')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('storage:check-low-stock')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('orders:expire-unpaid')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('storage:release-expired-reservations')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('payments:sync-pending')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('ledger:verify')->dailyAt('02:30')->onOneServer();
Schedule::command('ledger:reconcile fake')->dailyAt('03:00')->onOneServer()->environments(['local', 'staging']);
Schedule::command('orders:process-timers')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('holds:release')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('disputes:escalate-overdue')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('violations:process')->hourly()->withoutOverlapping()->onOneServer();
