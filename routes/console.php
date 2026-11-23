<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs (spec Part 19). Each milestone adds its own.
*/

Schedule::command('catalog:recount')->everyTenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('offers:expire')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('storage:check-low-stock')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
