<?php

declare(strict_types=1);

use Modules\Anomaly\Internal\Support\RobustStatistics;

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
    $offenders = [];

    foreach (glob(base_path('Modules/Anomaly/Resources/lang/*/settings.php')) ?: [] as $file) {
        /** @var array<string, mixed> $strings */
        $strings = require $file;

        $help = $strings['sensitivity_help'] ?? '';

        if (is_string($help) && str_contains($help, ':percent%')) {
            $offenders[] = basename(dirname($file));
        }
    }

    expect($offenders)->toBe(
        [],
        'These locales still promise a percentage band the detector never computed: '.implode(', ', $offenders),
    );
});

it('does not print the alert line as a plus-or-minus percentage either', function (): void {
    $offenders = [];

    foreach (glob(base_path('Modules/Anomaly/Resources/lang/*/alerts.php')) ?: [] as $file) {
        /** @var array<string, mixed> $strings */
        $strings = require $file;

        $line = $strings['sensitivity'] ?? '';

        if (is_string($line) && str_contains($line, ':percent%')) {
            $offenders[] = basename(dirname($file));
        }
    }

    expect($offenders)->toBe([], 'These locales still read "±:percent%" on the alert: '.implode(', ', $offenders));
});
