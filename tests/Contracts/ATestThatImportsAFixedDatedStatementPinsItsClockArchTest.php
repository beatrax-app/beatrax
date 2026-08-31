<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * @link ../../.docs/local_development/rebasing-a-statement-fixture.md
 */

/**
 * @return list<string>
 */
function fixtureClockTestFiles(): array
{
    $finder = Finder::create()->files()->name('*Test.php')->in([
        base_path('tests'),
        ...glob(base_path('Modules/*/tests')) ?: [],
    ]);

    $paths = [];
    foreach ($finder as $file) {
        $paths[] = $file->getPathname();
    }

    sort($paths);

    return $paths;
}

// The shipped statement fixtures carry absolute 2026 dates, and an import stamps
// now() on the run and on everything that reads back from it. A test that drives
// one without pinning the clock is measuring the wall clock, and stops covering
// anything time-relative the day the wall clock walks past the fixture.
//
// MT940 joins them without an import: its dates carry a two-digit year, so
// Mt940Tag61Parser resolves the century against now() and the parsed year is a
// function of the wall clock rather than of the file.
it('pins the clock in every test that reads a shipped statement fixture against now', function (): void {
    $offenders = [];

    foreach (fixtureClockTestFiles() as $path) {
        $source = (string) file_get_contents($path);

        $readsAFixtureAgainstNow = str_contains($source, 'tests/fixtures/asn-')
            && (
                str_contains($source, 'runAndConfirm(')
                || str_contains($source, 'runFromUpload(')
                || str_contains($source, 'asn-mt940-')
            );

        if (! $readsAFixtureAgainstNow) {
            continue;
        }

        $pinsTheClock = str_contains($source, 'setTestNow(')
            || str_contains($source, 'travelTo(')
            || str_contains($source, 'freezeTime(')
            || str_contains($source, 'freezeClockOnTheStatementFixtureWindow(');

        if (! $pinsTheClock) {
            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'These tests import a fixture whose dates are absolute and then read the',
        'result against the wall clock. Call $this->freezeClockOnTheStatementFixtureWindow()',
        'in beforeEach. Offenders:',
        ...$offenders,
    ]));
});
