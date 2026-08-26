<?php

declare(strict_types=1);

use Modules\Anomaly\Internal\Support\RobustStatistics;
use Modules\Core\Public\Enums\Locale;

// On the Samsung, /drift → Unusual charges opened with:
//
//   Cloudflare Inc · Large charge
//   baseline -€11.99 → actual: -€12.67 · detected 24 Aug · sensitivity ±50%
//
// -€11.99 to -€12.67 is 5.7%, and the settings copy above it promised
// "Flag charges more than 50% above your typical spend for that merchant or
// category." The alert is right and the sentence is wrong: the knob is a
// 0–100 level mapped onto the trip multiplier of a robust z-score, and its
// own documentation says so — "higher sensitivity means lower k means more
// alerts". A percentage band is not what it has ever computed.
//
// @link ../../../../.docs/features/anomaly/detector-maths.md#the-sensitivity-knob

// One literal ordering is not the rule. ":percent%", ":percent %", "%:percent"
// and "±%:percent" are the same wrong promise written four ways, and three
// locales sailed through a guard that only knew the first. The knob is a level
// on a scale of 100, never a band around a baseline, so neither mark has any
// business in copy that describes it — whatever side of the token it lands on.
const SENSITIVITY_PERCENTAGE_MARKS = '/[%±]/u';

// "of 100" reads as scaffolding rather than words, so it survived translation
// in every locale that has one: on device the Ukrainian alert says
// "чутливість 50 of 100".
const SENSITIVITY_UNTRANSLATED_SCALE = '/\bof\s+100\b/iu';

/** @return array<string, string> the $key line from every locale's $group file, keyed by locale */
function sensitivityCopyPerLocale(string $group, string $key): array
{
    $lines = [];

    foreach (glob(base_path('Modules/Anomaly/Resources/lang/*/'.$group.'.php')) ?: [] as $file) {
        /** @var array<string, mixed> $strings */
        $strings = require $file;

        $line = $strings[$key] ?? null;
        if (is_string($line)) {
            $lines[basename(dirname($file))] = $line;
        }
    }

    return $lines;
}

/**
 * @param  array<string, string>  $lines
 * @return list<string>
 */
function sensitivityCopyMatching(array $lines, string $pattern, bool $skipDefaultLocale = false): array
{
    $offenders = [];

    foreach ($lines as $locale => $line) {
        if ($skipDefaultLocale && $locale === Locale::DEFAULT) {
            continue;
        }

        if (preg_match($pattern, $line) === 1) {
            $offenders[] = $locale.': '.$line;
        }
    }

    return $offenders;
}

it('flags a charge the sensitivity knob never judged at all', function (): void {
    // The category path is a fixed p95 and takes no sensitivity argument, so
    // an alert it raises carries a "sensitivity ±50%" that had no bearing on
    // it. Tie-inclusive by design: a charge that repeats the biggest in its
    // category is exactly what the detector exists to catch.
    $category = [1199, 2400, 3576, 5817, 5817];

    expect(RobustStatistics::exceedsPercentile(5817, $category, RobustStatistics::CATEGORY_PERCENTILE))->toBeTrue();

    // And the same charge is nowhere near 50% above the sample it beat, which
    // is what the settings sentence told the reader it would have to be.
    $baseline = RobustStatistics::percentile(array_map('abs', $category), RobustStatistics::CATEGORY_PERCENTILE);

    expect(abs(5817 - $baseline) / $baseline)->toBeLessThan(0.5);
});

it('does not describe the knob as a percentage above typical spend', function (): void {
    $offenders = sensitivityCopyMatching(
        sensitivityCopyPerLocale('settings', 'sensitivity_help'),
        SENSITIVITY_PERCENTAGE_MARKS,
    );

    expect($offenders)->toBe(
        [],
        "These locales still promise a percentage band the detector never computed:\n  ".implode("\n  ", $offenders),
    );
});

it('does not print the alert line as a plus-or-minus percentage either', function (): void {
    $offenders = sensitivityCopyMatching(
        sensitivityCopyPerLocale('alerts', 'sensitivity'),
        SENSITIVITY_PERCENTAGE_MARKS,
    );

    expect($offenders)->toBe(
        [],
        "These locales still write the alert line as a percentage:\n  ".implode("\n  ", $offenders),
    );
});

it('translates the scale on the alert line rather than leaving the English words', function (): void {
    $offenders = sensitivityCopyMatching(
        sensitivityCopyPerLocale('alerts', 'sensitivity'),
        SENSITIVITY_UNTRANSLATED_SCALE,
        skipDefaultLocale: true,
    );

    expect($offenders)->toBe(
        [],
        "These locales render the English \"of 100\" to a reader who asked for their own language:\n  ".implode("\n  ", $offenders),
    );
});
