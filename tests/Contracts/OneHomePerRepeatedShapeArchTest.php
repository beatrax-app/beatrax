<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// A bare CarbonImmutable::parse() flattened to a day is the same shape whether
// or not it reaches for SafeDate first, so both spellings are scanned. Homes
// below flatten a value that cannot be blank, which is what SafeDate guards.
// One list, because the staleness check below and the scan above must cover the
// same set: a home added to one and not the other stops being re-proved.
// CalendarGrid builds its day with sprintf and from a literal, neither of which
// can arrive blank.
const FLATTEN_TO_DAY_HOMES = [
    'Modules/Forecasting/Internal/Pipeline/ChainAwareForecastRouter.php',
    'Modules/Calendar/Internal/Services/OccurrenceMatcher.php',
    'Modules/Calendar/Internal/Services/CalendarGrid.php',
];

it('flattens a parsed date to its day through SafeDate and nowhere else', function (): void {

    $shapes = [
        '~parseOrNull\([^;]*\)\?->startOfDay\(\)~',
        '~CarbonImmutable::parse\([^;]*?\)->startOfDay\(\)~',
    ];

    $offenders = [];

    foreach ([base_path('Modules'), base_path('app')] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }
            if (str_contains($path, '/tests/') || str_ends_with($path, 'Public/Support/SafeDate.php')) {
                continue;
            }

            $relative = str_replace(base_path().'/', '', $path);
            if (in_array($relative, FLATTEN_TO_DAY_HOMES, true)) {
                continue;
            }

            $source = (string) file_get_contents($path);

            foreach ($shapes as $shape) {
                $matches = PatternScan::allWithOffsets($shape, $source);

                foreach ($matches[0] as [$hit, $offset]) {
                    $offenders[] = sprintf(
                        '%s:%d  %s',
                        $relative,
                        substr_count(substr($source, 0, $offset), "\n") + 1,
                        trim($hit),
                    );
                }
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'A date-only field flattened to its day is SafeDate::normalisedDayOrNull(),',
        'and a date somebody SUPPLIED is SafeDate::dayOrNull(), which refuses it.',
        'CarbonImmutable::parse("") is NOW, so a blank field books itself today.',
        'These spell it out a second time instead:',
        ...$offenders,
    ]));
});

it('keeps every allowed flatten-to-day home present and still flattening', function (): void {

    $stale = [];
    foreach (FLATTEN_TO_DAY_HOMES as $relative) {
        $path = base_path($relative);
        if (! is_file($path)) {
            $stale[] = $relative.'  (file is gone)';

            continue;
        }
        if (preg_match('~CarbonImmutable::parse\([^;]*?\)->startOfDay\(\)~', (string) file_get_contents($path)) !== 1) {
            $stale[] = $relative.'  (no longer flattens a parse to a day)';
        }
    }

    expect($stale)->toBe([], implode("\n", [
        'An allowed flatten-to-day home no longer needs its exemption.',
        'Drop it from FLATTEN_TO_DAY_HOMES so the scan covers the file again:',
        ...$stale,
    ]));
});

it('spells the snooze windows once, in the enum that owns them', function (): void {
    $offenders = [];

    foreach ([base_path('Modules'), base_path('app'), base_path('resources')] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! PatternScan::matches('~\.(?:php|blade\.php)$~', $path)) {
                continue;
            }
            if (str_ends_with($path, 'Public/Enums/SnoozeWindow.php')) {
                continue;
            }

            $source = (string) file_get_contents($path);
            $comments = PatternScan::allWithOffsets('~\{\{--.*?--\}\}|/\*\*.*?\*/|/\*.*?\*/|//[^\n]*~s', $source);

            foreach ($comments[0] as [$comment, $offset]) {
                if (str_contains($comment, '1w') && str_contains($comment, '3m')) {
                    $offenders[] = sprintf(
                        '%s:%d',
                        str_replace(base_path().'/', '', $path),
                        substr_count(substr($source, 0, $offset), "\n") + 1,
                    );
                }
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'SnoozeWindow holds the three windows and their wire values. A comment',
        'that lists them again is a copy that goes stale the moment a fourth is',
        'added, and one already had:',
        ...$offenders,
    ]));
});
