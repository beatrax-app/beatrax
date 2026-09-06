<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// `db:restore` is only correct because `php artisan down` halts queue
// consumers. A supervisor with `force: true` keeps running in maintenance
// mode, so a job could re-open the SQLite file after `DB::purge()` and before
// the swap completes.

// Both composer roots ship a Horizon config of their own, and a run from either
// reads only its own base_path(). A supervisor forced on the phone is the same
// defect as one forced on the desktop, so both are read whichever root runs.
/** @return array<string, string> absolute path => the config's source */
function horizonSupervisorConfigs(): array
{
    $found = [];

    foreach (['config/horizon.php', 'mobile-app/config/horizon.php', '../config/horizon.php'] as $relative) {
        $path = base_path($relative);

        if (! is_file($path)) {
            continue;
        }

        $found[(string) realpath($path)] = (string) file_get_contents($path);
    }

    return $found;
}

/** how many supervisors $source keeps running while the application is down */
function horizonSupervisorsForcedOn(string $source): int
{
    return PatternScan::count(
        '/[\'"]force[\'"]\s*=>\s*true/',
        PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', $source),
    );
}

it('does not allow any Horizon supervisor to set force: true (HorizonForceFlagInvariant)', function (): void {
    $configs = horizonSupervisorConfigs();

    // Both denominators before any verdict: a walk that resolved no config, and
    // a config declaring no supervisor, each report the same clean answer a
    // clean config does.
    expect($configs)->not->toBeEmpty(
        'no Horizon config was found from this composer root, so this rule read nothing at all.'
    );

    $offenders = [];

    foreach ($configs as $path => $source) {
        $relative = str_replace(base_path().'/', '', $path);

        expect(PatternScan::count('/[\'"]supervisor[^\'"]*[\'"]\s*=>/', $source))->toBeGreaterThan(
            0,
            $relative.' declares no supervisor at all, so this rule has nothing to hold to it.'
        );

        if (horizonSupervisorsForcedOn($source) > 0) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These configure a supervisor that keeps consuming while `php artisan down` holds:',
        ...$offenders,
        '',
        'db:restore purges the connection and swaps the SQLite file underneath it. A forced',
        'supervisor re-opens the old handle in that window and writes into the file being replaced.',
    ]));
});

// A rule whose only assertion is that a pattern does not match passes when the
// pattern stopped matching anything at all, so the reader is driven against a
// planted config rather than against the tree.
it('sees a supervisor that keeps running while the application is down', function (): void {
    expect(horizonSupervisorsForcedOn("<?php return ['supervisor-1' => ['force' => true]];"))->toBe(1)
        ->and(horizonSupervisorsForcedOn("<?php return ['supervisor-1' => ['force' => false]];"))->toBe(0)
        ->and(horizonSupervisorsForcedOn("<?php\n// 'force' => true, once\nreturn [];"))->toBe(0);
});
