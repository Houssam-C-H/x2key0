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
Schedule::command('tickets:autoclose')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('invoices:issue-monthly')->monthlyOn(1, '04:00')->onOneServer();
Schedule::command('coupons:expire-claims')->hourly()->onOneServer();
Schedule::command('marketing:settle')->dailyAt('03:30')->onOneServer();
Schedule::command('affiliate:release-commissions')->hourly()->onOneServer();
Schedule::command('levels:recompute')->dailyAt('04:30')->onOneServer();
Schedule::command('home:refresh-trending')->hourly()->onOneServer();
Schedule::command('accounts:process-deletions')->dailyAt('05:00')->onOneServer();
Schedule::command('privacy:prune')->dailyAt('05:30')->onOneServer();
Schedule::command('ops:heartbeat')->everyMinute();
Schedule::command('ops:check')->everyFiveMinutes()->onOneServer();
Schedule::command('alerts:prices')->everyTenMinutes()->onOneServer()->withoutOverlapping();
Schedule::command('ops:backup')->dailyAt('03:15')->onOneServer()->withoutOverlapping()->when(static fn (): bool => config()->boolean('x2key.ops.backups_required'));
