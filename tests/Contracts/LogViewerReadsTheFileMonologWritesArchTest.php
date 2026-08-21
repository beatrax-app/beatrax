<?php

declare(strict_types=1);

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#the-log-tailer-reading-a-file-monolog-never-wrote
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
