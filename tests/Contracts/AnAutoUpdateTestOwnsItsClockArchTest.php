<?php

declare(strict_types=1);

// ElectronUpdateChannel calls a release stale once it is more than
// STALE_THRESHOLD_DAYS old, and picks the banner's kind from that. So a fixture
// date is only fixed on one side of that wall. A date that must read as *old*
// only ever gets older, and is safe against any clock. A date that must read as
// *fresh* is a countdown: it passes every day until the day it does not.
//
// That day was 2026-09-15. `2026-08-16` turned thirty days old at midnight UTC,
// `alertKindFor()` started answering `update.stale`, and the three tests
// asserting the available banner went red together on main during a release cut,
// having never been touched. The tamper tests beside them stayed green, because
// they assert that no banner appears -- a suite where only the happy path can
// break is a suite that stays green while the thing it covers stops working.
//
// Hence the rule below: assert the fresh side, own the clock. Then the fixture
// and `now` move together, and the calendar is not a participant. Tests that
// only assert the stale side are deliberately not covered -- their dates are
// already past the wall and walk away from it.
// @link ../../Modules/Core/Public/Services/ElectronUpdateChannel.php

/** @return list<string> every auto-update test file, in whichever composer root this run resolves */
function autoUpdateClockFiles(): array
{
    foreach (['Modules', '../Modules'] as $root) {
        $found = glob(base_path($root).'/*/tests/*/AutoUpdate/*Test.php') ?: [];

        if ($found !== []) {
            return $found;
        }
    }

    return [];
}

/** a test that asserts the fresh side of the staleness wall, and so carries an expiry date */
function autoUpdateClockAssertsFresh(string $body): bool
{
    foreach (['update.available', 'UpdateAlertKind::Available', 'alertKindFor'] as $needle) {
        if (str_contains($body, $needle)) {
            return true;
        }
    }

    return false;
}

it('finds the auto-update tests, and the fresh-side ones among them', function (): void {
    $files = autoUpdateClockFiles();

    // A positive control. The rule below is satisfied by an empty set, so
    // without this it would pass just as loudly if the glob stopped matching.
    expect($files)->not->toBe([], 'This rule found no auto-update tests at all, so it proved nothing about their clocks.');

    $fresh = array_filter($files, static fn (string $f): bool => autoUpdateClockAssertsFresh((string) file_get_contents($f)));
    expect($fresh)->not->toBe([], 'No auto-update test asserts the available banner, so the rule below has no subject and would pass empty.');
});

it('lets no fresh-side auto-update test read the wall clock', function (): void {
    $offenders = [];

    foreach (autoUpdateClockFiles() as $file) {
        $body = (string) file_get_contents($file);

        if (autoUpdateClockAssertsFresh($body) && str_contains($body, 'new SystemClock')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([], sprintf(
        "These tests assert the available banner while reading the real system clock, so their manifest dates expire on a date nobody chose:\n  %s\nSupply a Clock the test owns, pinned beside the fixture date, as StaleReleaseAnnouncementTest does.",
        implode("\n  ", $offenders),
    ));
});
