<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// Modules/Core/Public/Enums/Duration names the three whole-unit durations that
// otherwise recur as bare second counts, and its seconds() is the only place
// the conversion is done. The literals kept coming back anyway — a TTL of
// 86_400 here, a `* 60 * 1000` there — because nothing asked for them.
//
// Scoped by NAME, not by value: 60 is a minute in TTL_SECONDS and a money
// amount in target_minor, an MT940 field in :60F:, and a Tailwind class in
// min-w-60. A value-only scan cannot tell those apart and would be turned off
// the first time it cried wolf. A number whose own name says seconds is the
// one place the reading is unambiguous.
const DURATION_NAME_TOKENS = 'SECOND|TTL|TIMEOUT|LIFETIME|DECAY|GRACE';

// A name can carry one of those tokens and still not be a second count.
// CEREMONY_MAX_AGE_MINUTES = 60 is an hour measured in minutes, and
// MAX_PER_WINDOW = 60 is a number of frames — both read as durations to a
// token match and neither is one.
const DURATION_NAME_EXCLUSIONS = 'MINUTE|HOUR|DAY|MAX_PER|PER_|COUNT|LIMIT|ATTEMPT';

// There is deliberately no pin list. The one entry this carried named
// InboxScanStateMachine for its BACKOFF_SCHEDULE, and that constant matches
// neither half of the rule — its name carries no duration token and its value
// is an array rather than a bare literal — so the exemption excused nothing
// while reading as a decision somebody had taken. Tuned curves are
// ATuningNumberIsNamedOnceAndAnswersToTheRealStatement's to hold.

/**
 * Modules and app, minus the three subtrees that describe rather than decide:
 * a test's fixture, a migration's schema and a lang file's data. Duration.php
 * is where the enum states the conversion, so it is the one file allowed to.
 *
 * @return list<string>
 */
function durationScannedFiles(): array
{
    $paths = [];

    foreach ([base_path('Modules'), base_path('app')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            $path = $file->getPathname();

            if (! str_ends_with($path, '.php')) {
                continue;
            }

            if (str_contains($path, '/tests/') || str_contains($path, '/Migrations/')
                || str_contains($path, '/lang/') || str_ends_with($path, '/Duration.php')) {
                continue;
            }

            $paths[] = $path;
        }
    }

    sort($paths);

    return $paths;
}

/**
 * Terminated by `;` alone, so a parameter default is not swept in: PHP constant
 * expressions cannot call a method, so `int $retryAfterSeconds = 60` has no enum
 * form to move to and flagging it would be an instruction to do the impossible.
 * Class constants are excluded for the same reason and answer it differently —
 * they become a static method, the way JobProgressCache::ttlSeconds() already does.
 *
 * @return list<string> one `NAME = value` per whole-unit duration counted out
 */
function durationLiteralsNamedInSeconds(string $source): array
{
    $pattern = '/(?<name>[A-Za-z_]*(?:'.DURATION_NAME_TOKENS.')[A-Za-z_]*)\s*=\s*(?<value>60|3_?600|86_?400)\s*;/i';
    $found = [];

    foreach (PatternScan::sets($pattern, $source) as $match) {
        if (preg_match('/'.DURATION_NAME_EXCLUSIONS.'/i', $match['name']) === 1) {
            continue;
        }

        $found[] = trim($match['name']).' = '.$match['value'];
    }

    return $found;
}

/** `* 60 * 1000` and `* 60_000` are the same minute, spelled twice. */
function handWrittenMinuteMillisecondCount(string $source): int
{
    return PatternScan::count('/\*\s*(?:60\s*\*\s*1000|60_?000)\b/', $source);
}

it('reaches for the enum wherever a whole-unit duration is named', function (): void {
    $files = durationScannedFiles();

    // A scan that walked nothing would report a clean tree. The floor is the
    // assertion that it read one. Two thousand four hundred files stand here.
    expect(count($files))->toBeGreaterThan(
        500,
        'The walk read almost nothing, so the empty offender list below is a tree nobody opened.',
    );

    $offenders = [];

    foreach ($files as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        foreach (durationLiteralsNamedInSeconds((string) file_get_contents($path)) as $named) {
            $offenders[] = $relative.' — '.$named;
        }
    }

    expect($offenders)->toBe([], "These name a whole-unit duration and then count it out in seconds:\n  ".implode("\n  ", $offenders));
});

it('converts minutes to milliseconds through the enum rather than by hand', function (): void {
    $files = durationScannedFiles();

    expect(count($files))->toBeGreaterThan(
        500,
        'The walk read almost nothing, so the empty offender list below is a tree nobody opened.',
    );

    $offenders = [];

    foreach ($files as $path) {
        // Both spellings were in the app-lock idle window, in three files.
        $found = handWrittenMinuteMillisecondCount((string) file_get_contents($path));

        if ($found > 0) {
            $offenders[] = str_replace(base_path().'/', '', $path).' — '.$found.' hand-written minute-to-millisecond conversion(s)';
        }
    }

    expect($offenders)->toBe([], "Duration::Minute->milliseconds() is the one home for this:\n  ".implode("\n  ", $offenders));
});

// Both verdicts above are read off lists that are empty on a clean tree and on a
// scan that stopped. These plant each thing the readers have to see, and each
// near miss they have to leave alone — the exclusion list in particular, which
// carries the whole rule's usefulness and is otherwise proved by nothing.
it('sees a duration counted out in seconds, and leaves the shapes that are not one alone', function (): void {
    expect(durationLiteralsNamedInSeconds('<?php $sessionTtlSeconds = 86_400;'))
        ->toBe(['sessionTtlSeconds = 86_400'], 'a day counted out in seconds went unreported');

    expect(durationLiteralsNamedInSeconds('<?php const SECONDS_PER_HOUR = 3600;'))
        ->toBe([], 'a name the exclusion list covers was reported anyway');

    expect(durationLiteralsNamedInSeconds('<?php function retry(int $retryAfterSeconds = 60): void {}'))
        ->toBe([], 'a parameter default has no enum form to move to and must not be reported');

    expect(durationLiteralsNamedInSeconds('<?php $targetMinor = 3600;'))
        ->toBe([], 'a number whose name says nothing about time was read as a duration');

    expect(handWrittenMinuteMillisecondCount('<?php $idle = $minutes * 60 * 1000;'))
        ->toBe(1, 'a minute spelled as * 60 * 1000 went uncounted');

    expect(handWrittenMinuteMillisecondCount('<?php $idle = $minutes * 60_000;'))
        ->toBe(1, 'the same minute spelled as * 60_000 went uncounted');

    expect(handWrittenMinuteMillisecondCount('<?php $window = Duration::Minute->milliseconds();'))
        ->toBe(0, 'the enum form was read as a hand-written conversion');
});
