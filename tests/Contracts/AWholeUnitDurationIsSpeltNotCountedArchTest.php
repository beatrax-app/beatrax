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

// Tuned numbers that happen to be whole units are still tuned numbers: a
// backoff schedule of [60, 300, 900, 3600] is one shape and taking two of its
// four terms to the enum would leave it saying less than it does now.
// ATuningNumberIsNamedOnceAndAnswersToTheRealStatement owns those.
/** @var array<string, string> */
const DURATION_LITERAL_PINS = [
    'Modules/EmailScan/Internal/InboxScanStateMachine.php' => 'BACKOFF_SCHEDULE [60, 300, 900, 3600] — a curve, not four durations; 300 and 900 have no case to reach for.',
];

/** @return list<string> */
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

it('reaches for the enum wherever a whole-unit duration is named', function (): void {
    $files = durationScannedFiles();

    // A scan that walked nothing would report a clean tree. The floor is the
    // assertion that it read one.
    expect(count($files))->toBeGreaterThan(500);

    // Terminated by `;` alone, so a parameter default is not swept in: PHP
    // constant expressions cannot call a method, so `int $retryAfterSeconds =
    // 60` has no enum form to move to and flagging it would be an instruction
    // to do the impossible. Class constants are excluded for the same reason
    // and answer it differently — they become a static method, the way
    // JobProgressCache::ttlSeconds() already does.
    $pattern = '/(?<name>[A-Za-z_]*(?:'.DURATION_NAME_TOKENS.')[A-Za-z_]*)\s*=\s*(?<value>60|3_?600|86_?400)\s*;/i';
    $offenders = [];

    foreach ($files as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        if (array_key_exists($relative, DURATION_LITERAL_PINS)) {
            continue;
        }

        $source = (string) file_get_contents($path);
        $matches = PatternScan::sets($pattern, $source);

        foreach ($matches as $match) {
            if (preg_match('/'.DURATION_NAME_EXCLUSIONS.'/i', $match['name']) === 1) {
                continue;
            }

            $offenders[] = $relative.' — '.trim($match['name']).' = '.$match['value'];
        }
    }

    expect($offenders)->toBe([], "These name a whole-unit duration and then count it out in seconds:\n  ".implode("\n  ", $offenders));
});

it('converts minutes to milliseconds through the enum rather than by hand', function (): void {
    $offenders = [];

    foreach (durationScannedFiles() as $path) {
        $source = (string) file_get_contents($path);

        // `* 60 * 1000` and `* 60_000` are the same minute, spelled twice. Both
        // were in the app-lock idle window, in three files, in two spellings.
        $found = PatternScan::count('/\*\s*(?:60\s*\*\s*1000|60_?000)\b/', $source);

        if ($found > 0) {
            $offenders[] = str_replace(base_path().'/', '', $path).' — '.$found.' hand-written minute-to-millisecond conversion(s)';
        }
    }

    expect($offenders)->toBe([], "Duration::Minute->milliseconds() is the one home for this:\n  ".implode("\n  ", $offenders));
});

it('keeps every pin pointing at a file that still exists', function (): void {
    foreach (DURATION_LITERAL_PINS as $relative => $why) {
        expect(is_file(base_path($relative)))->toBeTrue($relative.' is pinned but no longer here — delete the pin.');
        expect($why)->not->toBe('');
    }
});
