<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

if (Event::hasMacro('onAnyNetwork')) {
    Schedule::command('sync:mobile-pull')
        ->name('mobile.sync-pull')
        ->everyFifteenMinutes()
        ->onAnyNetwork()
        ->withoutOverlapping(10);
}
