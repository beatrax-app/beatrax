<?php

declare(strict_types=1);

/*
 * The Dev Console's log tailer reads `dailyLogFile()`, which resolves
 * `laravel-YYYY-MM-DD.log` — the name Laravel's RotatingFileHandler gives the
 * path handed to it. That only holds on the `daily` channel.
 *
 * Both roots shipped `LOG_STACK=single`, so Monolog wrote `laravel.log` and the
 * tailer read a file that never existed. Measured on an iPhone mid-500: the
 * Logs panel reported "0 lines today · 0 B across 0 daily files" while the app
 * was serving an unhandled exception — the one surface built for reading
 * errors, blind to them.
 */

it('configures the channel the log viewer knows how to read', function (): void {
    $offenders = [];

    foreach (['.env.example', '../.env.example', 'mobile-app/.env.example'] as $relative) {
        $path = base_path($relative);

        if (! is_file($path)) {
            continue;
        }

        $env = (string) file_get_contents($path);

        if (preg_match('/^LOG_STACK=(.*)$/m', $env, $match) !== 1) {
            continue;
        }

        $channel = trim($match[1]);

        if ($channel !== 'daily') {
            $offenders[] = $relative.' → '.$channel;
        }
    }

    expect($offenders)->toBe([], sprintf(
        "The log viewer globs laravel-*.log, so these leave it reading nothing:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});
