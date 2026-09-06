<?php

declare(strict_types=1);

// A pinned APP_TIMEZONE in a template ships one zone to every reader who
// installs the bundle it is copied into. The desktop template pinned
// Europe/Amsterdam, the framework default is UTC, and the two shells therefore
// disagreed about which day a transaction falls on — which is not a display
// detail, because `app.timezone` is the frame a DATETIME column is written in.

/**
 * @return array<string, string> path => contents, for every template a shell copies
 */
function shippedEnvironmentTemplates(): array
{
    $templates = [];

    foreach (['.env.example', '.env.bundled', 'mobile-app/.env.bundled'] as $relative) {
        $path = base_path($relative);

        if (is_file($path)) {
            $templates[$relative] = (string) file_get_contents($path);
        }
    }

    return $templates;
}

/**
 * @return list<string> the uncommented assignments in a template
 */
function envAssignmentsIn(string $contents): array
{
    $assignments = [];

    foreach (explode("\n", $contents) as $line) {
        $trimmed = ltrim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        if (preg_match('/^([A-Z0-9_]+)=/', $trimmed, $matches) === 1) {
            $assignments[] = $matches[1];
        }
    }

    return $assignments;
}

it('reads templates that actually carry assignments, so an absence means something', function (): void {
    $templates = shippedEnvironmentTemplates();

    expect($templates)->not->toBe([]);

    // toContain takes needles, not a message, so the name of the template that
    // failed goes in a variable the failure prints rather than in a second
    // argument it would look for as a second needle.
    $withoutAppEnv = [];

    foreach ($templates as $relative => $contents) {
        if (! in_array('APP_ENV', envAssignmentsIn($contents), true)) {
            $withoutAppEnv[] = $relative;
        }
    }

    expect($withoutAppEnv)->toBe([], implode("\n", [
        'These templates carry no APP_ENV, so they are not the templates this',
        'guard thinks it is reading and its silence about APP_TIMEZONE is worth',
        'nothing:',
        ...$withoutAppEnv,
    ]));
});

it('lets no shipped template pin the zone the reader reads days in', function (): void {
    $offenders = [];

    foreach (shippedEnvironmentTemplates() as $relative => $contents) {
        if (in_array('APP_TIMEZONE', envAssignmentsIn($contents), true)) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These templates pin APP_TIMEZONE, so every install made from them reads',
        'the packager\'s day rather than its own:',
        ...$offenders,
        '',
        'Leave it unset. InstallTimezone reads the machine, a stored choice in',
        'settings overrides that, and an environment that names one outranks both',
        '— which is what a server deployment wants and a bundle does not.',
    ]));
});

// The resolution is a middleware, so a bundle that stopped registering it would
// pass every test above and still serve UTC to a reader in Auckland.
it('binds the zone on the web group before the route runs', function (): void {
    $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

    expect($bootstrap)
        ->toContain('SetInstallTimezone::class')
        ->toContain('use Modules\Core\Internal\Http\Middleware\SetInstallTimezone;');
});
