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

/** Both spellings of the same flatten, so reaching for SafeDate first does not hide one. */
const FLATTEN_TO_DAY_SHAPES = [
    '~parseOrNull\([^;]*\)\?->startOfDay\(\)~',
    '~CarbonImmutable::parse\([^;]*?\)->startOfDay\(\)~',
];

/**
 * @return list<string> "$label:$line  $hit" for every flatten the source writes
 */
function flattenToDayOffendersIn(string $source, string $label): array
{
    $offenders = [];

    foreach (FLATTEN_TO_DAY_SHAPES as $shape) {
        foreach (PatternScan::allWithOffsets($shape, $source)[0] as [$hit, $offset]) {
            $offenders[] = sprintf(
                '%s:%d  %s',
                $label,
                substr_count(substr($source, 0, $offset), "\n") + 1,
                trim($hit),
            );
        }
    }

    return $offenders;
}

it('flattens a parsed date to its day through SafeDate and nowhere else', function (): void {
    $offenders = [];
    $walked = 0;

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

            $walked++;

            $relative = str_replace(base_path().'/', '', $path);
            if (in_array($relative, FLATTEN_TO_DAY_HOMES, true)) {
                continue;
            }

            foreach (flattenToDayOffendersIn((string) file_get_contents($path), $relative) as $offender) {
                $offenders[] = $offender;
            }
        }
    }

    expect($walked)->toBeGreaterThan(2000, 'The walk read almost no PHP, so a clean answer below is the walk being broken rather than the tree being right.');

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
        // Re-proved through the detector the scan above drives, not through a
        // second copy of one of its two patterns: a home that moved to the
        // other spelling was still exempted and no longer re-checked.
        if (flattenToDayOffendersIn((string) file_get_contents($path), $relative) === []) {
            $stale[] = $relative.'  (no longer flattens a parse to a day)';
        }
    }

    expect($stale)->toBe([], implode("\n", [
        'An allowed flatten-to-day home no longer needs its exemption.',
        'Drop it from FLATTEN_TO_DAY_HOMES so the scan covers the file again:',
        ...$stale,
    ]));
});

it('reads both spellings of the flatten and leaves a guarded one alone', function (): void {
    $bare = "<?php\n\$d = CarbonImmutable::parse(\$row['posted_at'])->startOfDay();\n";
    $reaching = "<?php\n\$d = SafeDate::parseOrNull(\$row['posted_at'])?->startOfDay();\n";
    // The near miss: the seam's own call, which flattens nothing here.
    $guarded = "<?php\n\$d = SafeDate::normalisedDayOrNull(\$row['posted_at']);\n";

    expect(flattenToDayOffendersIn($bare, 'v'))->toBe(["v:2  CarbonImmutable::parse(\$row['posted_at'])->startOfDay()"])
        ->and(flattenToDayOffendersIn($reaching, 'v'))->toBe(["v:2  parseOrNull(\$row['posted_at'])?->startOfDay()"])
        ->and(flattenToDayOffendersIn($guarded, 'v'))->toBe([]);
});

it('spells the snooze windows once, in the enum that owns them', function (): void {
    $offenders = [];
    $walked = 0;

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
            $walked++;

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

    expect($walked)->toBeGreaterThan(2000, 'The walk read almost nothing, so a clean answer below is the walk being broken rather than the tree being right.');

    expect($offenders)->toBe([], implode("\n", [
        'SnoozeWindow holds the three windows and their wire values. A comment',
        'that lists them again is a copy that goes stale the moment a fourth is',
        'added, and one already had:',
        ...$offenders,
    ]));
});

// Auth, Pots, Reports and Recurring's detail page carry the suffix inside the
// translated page_title value itself, in all 26 locales, so the lang tree is
// out of scope here rather than a second home. Tests are out too: a title
// assertion that reaches for the constant proves nothing about the render.
it('spells the brand title suffix once, in the class that owns it', function (): void {
    $offenders = [];
    $walked = 0;

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
            if (str_ends_with($path, 'Public/Support/Brand.php') || str_contains($path, '/Resources/lang/')) {
                continue;
            }
            if (str_contains($path, '/tests/')) {
                continue;
            }

            $walked++;

            $source = (string) file_get_contents($path);
            $matches = PatternScan::allWithOffsets('~ \xc2\xb7 Beatrax~', $source);

            if ($matches[0] === []) {
                continue;
            }

            foreach ($matches[0] as [, $offset]) {
                $offenders[] = sprintf(
                    '%s:%d',
                    str_replace(base_path().'/', '', $path),
                    substr_count(substr($source, 0, $offset), "\n") + 1,
                );
            }
        }
    }

    expect($walked)->toBeGreaterThan(2000, 'The walk read almost nothing, so a clean answer below is the walk being broken rather than the tree being right.');

    expect($offenders)->toBe([], implode("\n", [
        'Brand::TITLE_SUFFIX is the tail a titled page appends, and Brand::NAME',
        'is the product name. The name has been restyled once already; these',
        'write it out again where the next restyle will not find them:',
        ...$offenders,
    ]));
});

it('keeps every consumer of the brand suffix reaching for the class', function (): void {
    $consumers = [
        'Modules/Tax/Internal/Http/Livewire/TaxPage.php',
        'Modules/Shell/Resources/views/dashboard.blade.php',
        'Modules/Onboarding/Resources/views/layouts/app-wizard.blade.php',
        'resources/views/components/errors/beatrax-error.blade.php',
    ];

    $stale = [];
    foreach ($consumers as $relative) {
        $path = base_path($relative);
        if (! is_file($path)) {
            $stale[] = $relative.'  (file is gone)';

            continue;
        }
        if (! str_contains((string) file_get_contents($path), 'Brand::TITLE_SUFFIX')) {
            $stale[] = $relative.'  (no longer reaches Brand::TITLE_SUFFIX)';
        }
    }

    expect($stale)->toBe([], implode("\n", [
        'The scan above only proves nobody spells the suffix out. These four',
        'prove the seam is still reached -- one PHP render(), one blade @extends,',
        'and the two layouts that write a <title> of their own:',
        ...$stale,
    ]));
});
