<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\Fmt;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#month-first-dates-in-a-dutch-ui
 */

// `L` is the locale's own short date, and English writes it month-first —
// 08/20/2026 on the locale a fresh install runs on. Matched by SHAPE rather
// than by the two literal spellings the rule was written against: the one real
// call site spells it `getIsoFormats(self::locale())['L']`, which neither
// literal reaches, so the whole rule was a pin over a site it could not see.
// `['LT']` is the locale's short TIME and is deliberately not matched.
const EUROPEAN_DATE_RAW_SHORT_DATE = "/isoFormat\\(\\s*'L'\\s*\\)|getIsoFormats\\([^;]*?\\)\\s*\\[\\s*'L'\\s*\\]/";

// Fmt is where the correction lives, so it is the one caller allowed to ask
// Carbon for the raw locale pattern. Pinned by path with the reason it was
// granted for, and re-checked below: when the file stops reading that way the
// exemption has outlived what earned it.
const EUROPEAN_DATE_RAW_SHORT_DATE_PINS = [
    'Modules/Core/Public/Support/Fmt.php' => [
        'reason' => 'the correction itself: it reads the locale-native pattern precisely so every other caller does not have to, and rewrites the month-first ones before handing them on',
        'proves' => '/FALLBACK_DATE_PATTERN/',
    ],
];

/** @return list<string> every PHP and Blade file that ships, the suite aside */
function europeanDateSourceFiles(): array
{
    return RepoTree::files(RepoTree::PRODUCTION_PHP);
}

/**
 * The month-first `translatedFormat()` patterns in one source. M/F is the
 * month, j/d the day: a pattern that opens with the month and then names the
 * day is US order.
 *
 * @return list<string>
 */
function europeanDateMonthFirstIn(string $source): array
{
    $found = [];

    foreach (PatternScan::all("/translatedFormat\\('([^']+)'\\)/", $source)[1] as $pattern) {
        if (preg_match('/^[MF][^jd]*[jd]/', $pattern) === 1) {
            $found[] = $pattern;
        }
    }

    return $found;
}

/** @return bool whether this source takes the locale's short date raw */
function europeanDateRawShortDateIn(string $source): bool
{
    return PatternScan::matches(EUROPEAN_DATE_RAW_SHORT_DATE, $source);
}

it('never formats a date month-first', function (): void {
    $files = europeanDateSourceFiles();

    // The floor sits far under the 6,600 shipped files this walk opens.
    expect(count($files))->toBeGreaterThan(
        2000,
        'The production walk opened almost nothing, so no date format was read at all.'
    );

    $offenders = [];
    $patterns = 0;

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);
        $patterns += PatternScan::count("/translatedFormat\\('/", $source);

        foreach (europeanDateMonthFirstIn($source) as $pattern) {
            $offenders[] = str_replace(RepoTree::root().'/', '', $path)." — '{$pattern}'";
        }
    }

    expect($patterns)->toBeGreaterThan(
        20,
        'No translatedFormat() pattern was read at all, so the reader has stopped matching.'
    );

    expect($offenders)->toBe([], sprintf(
        "These render the month before the day:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});

it('routes every locale-native short date through the one place that corrects it', function (): void {
    $files = europeanDateSourceFiles();

    expect(count($files))->toBeGreaterThan(
        2000,
        'The production walk opened almost nothing, so no short-date call was read at all.'
    );

    $offenders = [];

    foreach ($files as $path) {
        $relative = str_replace(RepoTree::root().'/', '', $path);

        if (array_key_exists($relative, EUROPEAN_DATE_RAW_SHORT_DATE_PINS)) {
            continue;
        }

        if (europeanDateRawShortDateIn((string) file_get_contents($path))) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], sprintf(
        "These take the locale's short date raw instead of through Fmt::shortDate()/datePattern():\n  - %s",
        implode("\n  - ", $offenders),
    ));
});

it('still holds each pinned exemption to the reason it was granted for', function (): void {
    foreach (EUROPEAN_DATE_RAW_SHORT_DATE_PINS as $relative => $pin) {
        $source = (string) file_get_contents(RepoTree::root().'/'.$relative);

        expect($source)->toMatch($pin['proves'], $relative.' no longer reads as "'.$pin['reason'].'"');
    }
});

// A pin the walk no longer reaches excuses nothing, and reads as considered.
it('keeps no pin the walk no longer reaches', function (): void {
    $reached = array_values(array_filter(
        array_keys(EUROPEAN_DATE_RAW_SHORT_DATE_PINS),
        static fn (string $relative): bool => is_file(RepoTree::root().'/'.$relative)
            && europeanDateRawShortDateIn((string) file_get_contents(RepoTree::root().'/'.$relative)),
    ));

    expect($reached)->toBe(array_keys(EUROPEAN_DATE_RAW_SHORT_DATE_PINS), implode("\n  ", [
        'A pinned file that no longer takes the locale short date raw has outlived its',
        'exemption. Delete the pin rather than leave a claim standing that the next',
        'reader will trust.',
    ]));
});

// The shape reader is what the rule above gets its subject from, and one that
// matched nothing would wave every call site through.
it('reads the short date in every spelling and leaves the short time alone', function (): void {
    expect(europeanDateRawShortDateIn("<?php return \$date->isoFormat('L');"))->toBeTrue();
    expect(europeanDateRawShortDateIn("<?php return CarbonImmutable::now()->getIsoFormats(self::locale())['L'];"))->toBeTrue();
    expect(europeanDateRawShortDateIn("<?php return \$anchor->getIsoFormats()['L'] ?? 'DD-MM-YYYY';"))->toBeTrue();

    expect(europeanDateRawShortDateIn("<?php return \$anchor->getIsoFormats()['LT'] ?? 'HH:mm';"))->toBeFalse();
    expect(europeanDateRawShortDateIn("<?php return Fmt::shortDate(\$date);"))->toBeFalse();

    expect(europeanDateMonthFirstIn("<?php \$a = \$d->translatedFormat('M j, Y');"))->toBe(['M j, Y']);
    expect(europeanDateMonthFirstIn("<?php \$a = \$d->translatedFormat('j M Y');"))->toBe(
        [],
        'A day-first pattern is the correct order and must not be reported.'
    );
});

it('corrects only the locales that write the month first', function (): void {
    $rendered = [];

    foreach (Locale::cases() as $case) {
        app()->setLocale($case->value);
        $rendered[$case->value] = Fmt::shortDate('2026-08-05');
    }

    app()->setLocale(Locale::DEFAULT);

    expect(count($rendered))->toBeGreaterThan(
        20,
        'No shipped language rendered a short date, so this rule checked nothing.'
    );

    // 5 August. A month-first rendering puts the 8 before the 5; year-first is
    // left alone, because 2026-08-05 is unambiguous and reordering it would be
    // the odd thing to a Swedish reader.
    $monthFirst = [];

    foreach ($rendered as $locale => $date) {
        $digits = PatternScan::replace('/\D+/', ' ', $date);
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
