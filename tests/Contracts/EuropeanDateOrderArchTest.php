<?php

declare(strict_types=1);
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\Fmt;
use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#month-first-dates-in-a-dutch-ui
 */
it('never formats a date month-first', function (): void {
    $offenders = [];

    foreach ([base_path('Modules'), base_path('resources')] as $root) {
        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            $path = $file->getPathname();

            if (! $file->isFile() || ! PatternScan::matches('/\.(php|blade\.php)$/', $path)) {
                continue;
            }

            if (str_contains($path, '/tests/')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            // M/F is the month, j/d the day. A pattern that opens with the
            // month and then names the day is US order.
            $matches = PatternScan::all("/translatedFormat\('([^']+)'\)/", $source);

            foreach ($matches[1] as $pattern) {
                if (preg_match('/^[MF][^jd]*[jd]/', $pattern) === 1) {
                    $offenders[] = str_replace(base_path().'/', '', $path)." — '{$pattern}'";
                }
            }
        }
    }

    expect($offenders)->toBe([], sprintf(
        "These render the month before the day:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});

it('routes every locale-native short date through the one place that corrects it', function (): void {
    $offenders = [];

    foreach ([base_path('Modules'), base_path('resources')] as $root) {
        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            $path = $file->getPathname();

            if (! $file->isFile() || ! PatternScan::matches('/\.(php|blade\.php)$/', $path)) {
                continue;
            }

            // Fmt is where the correction lives, so it is the one caller
            // allowed to ask Carbon for the raw locale pattern.
            if (str_contains($path, '/tests/') || str_ends_with($path, '/Support/Fmt.php')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            // `L` is the locale's own short date, and English writes it
            // month-first — 08/20/2026 on the locale a fresh install runs on.
            // getIsoFormats()['L'] is the same pattern reached a second way.
            if (str_contains($source, "isoFormat('L')") || str_contains($source, "getIsoFormats()['L']")) {
                $offenders[] = str_replace(base_path().'/', '', $path);
            }
        }
    }

    expect($offenders)->toBe([], sprintf(
        "These take the locale's short date raw instead of through Fmt::shortDate()/datePattern():\n  - %s",
        implode("\n  - ", $offenders),
    ));
});

it('corrects only the locales that write the month first', function (): void {
    $rendered = [];

    foreach (Locale::cases() as $case) {
        app()->setLocale($case->value);
        $rendered[$case->value] = Fmt::shortDate('2026-08-05');
    }

    app()->setLocale(Locale::DEFAULT);

    // 5 August. A month-first rendering puts the 8 before the 5; year-first is
    // left alone, because 2026-08-05 is unambiguous and reordering it would be
    // the odd thing to a Swedish reader.
    $monthFirst = [];

    foreach ($rendered as $locale => $date) {
        $digits = preg_replace('/\D+/', ' ', $date) ?? '';
        $parts = array_values(array_filter(explode(' ', trim($digits)), static fn (string $p): bool => $p !== ''));

        if (count($parts) === 3 && (int) $parts[0] === 8 && (int) $parts[1] === 5) {
            $monthFirst[] = $locale.' → '.$date;
        }
    }

    expect($monthFirst)->toBe([], 'these still write the month before the day: '.implode(', ', $monthFirst));
    expect($rendered['en'])->toBe('05/08/2026')
        ->and($rendered['nl'])->toBe('05-08-2026')
        ->and($rendered['sv'])->toBe('2026-08-05');
});
