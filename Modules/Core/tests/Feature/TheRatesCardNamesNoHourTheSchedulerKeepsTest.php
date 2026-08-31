<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Public\Enums\Locale;

// The FX refresh ran at 09:00 until the hour was dropped: a phone's background
// runner takes a repeat period and has no wall clock to hand it, so the entry
// became ->daily(). The settings card kept naming 09:00 in all twenty-six
// languages, which is now an hour no device refreshes at.

it('schedules the FX refresh on a repeat period, with no hour left to name', function (): void {
    $found = null;
    foreach (app(Schedule::class)->events() as $event) {
        /** @var ScheduledEvent $event */
        if ((string) $event->description === 'fx.daily-refresh') {
            $found = $event;
        }
    }

    expect($found)->not->toBeNull();
    expect($found->expression)->toBe('0 0 * * *');
});

it('names no clock hour on the exchange-rates card, in any locale', function (): void {
    $offenders = [];

    foreach (Locale::cases() as $locale) {
        $file = base_path('Modules/Core/Resources/lang/'.$locale->value.'/settings.php');
        $lines = require $file;
        $line = $lines['exchange_rates']['next_refresh'];

        if (preg_match('/\d{1,2}[.:]\d{2}/', (string) $line) === 1) {
            $offenders[] = $locale->value.' — '.$line;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'The exchange-rates card names an hour the scheduler stopped keeping:',
        '  '.implode("\n  ", $offenders),
        '',
        'fx.daily-refresh is ->daily(), and on a phone even that is a floor the OS may',
        'miss — a wall clock is the one thing this line cannot promise.',
    ]));
});
