<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs (spec Part 19). Each milestone adds its own.
*/

Schedule::command('catalog:recount')->everyTenMinutes()->withoutOverlapping()->onOneServer();
