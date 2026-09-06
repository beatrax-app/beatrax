<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// The shipped environments both composer roots package. `../.env.example` is
// how the mobile root reaches the desktop's, and a run from either root has to
// read both: a stack configured on one shell and not the other leaves the log
// viewer reading nothing on exactly one of them.
const LOG_VIEWER_ENV_TEMPLATES = ['.env.example', '../.env.example', 'mobile-app/.env.example'];

/** the LOG_STACK $env selects, or null when it names none */
function logViewerChannelIn(string $env): ?string
{
    $named = PatternScan::first('/^LOG_STACK=(.*)$/m', $env);

    return $named === [] ? null : trim((string) $named[1]);
}

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#the-log-tailer-reading-a-file-monolog-never-wrote
 */
it('configures the channel the log viewer knows how to read', function (): void {
    $offenders = [];
    $read = 0;

    foreach (LOG_VIEWER_ENV_TEMPLATES as $relative) {
        $path = base_path($relative);

        if (! is_file($path)) {
            continue;
        }

        $channel = logViewerChannelIn((string) file_get_contents($path));

        // A template that names no stack at all is the same failure as one
        // naming the wrong stack: LOG_STACK falls to the framework's `single`,
        // which writes laravel.log and the viewer globs laravel-*.log.
        if ($channel === null) {
            $offenders[] = $relative.' → names no LOG_STACK';

            continue;
        }

        $read++;

        if ($channel !== 'daily') {
            $offenders[] = $relative.' → '.$channel;
        }
    }

    // Read before the verdict: with no template resolved, or none of them
    // naming the key, the offender list below is empty because nothing was
    // looked at rather than because every shell is configured.
    expect($read)->toBeGreaterThan(0, implode("\n", [
        'No shipped environment template naming LOG_STACK was found from this composer root.',
        'Every one of '.implode(', ', LOG_VIEWER_ENV_TEMPLATES).' was missing or silent, so this rule read nothing.',
    ]));

    expect($offenders)->toBe([], sprintf(
        "The log viewer globs laravel-*.log, so these leave it reading nothing:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});

it('tells a template that selects the daily stack from one that selects nothing', function (): void {
    expect(logViewerChannelIn("APP_ENV=local\nLOG_STACK=daily\nLOG_LEVEL=debug\n"))->toBe('daily')
        ->and(logViewerChannelIn("LOG_STACK=single\n"))->toBe('single')
        ->and(logViewerChannelIn("APP_ENV=local\n# LOG_STACK is unset\n"))->toBeNull();
});
