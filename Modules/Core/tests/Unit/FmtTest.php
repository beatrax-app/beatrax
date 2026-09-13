<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\Fmt;

// The mobile PHP build ships ICU data for English only, so on device
// NumberFormatter cannot be built for any of the other twenty-five languages
// and the views calling Fmt would 500 on a language switch. A locale ICU
// refuses stands in for that, which a host with full ICU data cannot reproduce.

it('formats through ICU when the runtime can', function (): void {
    app()->make(Translator::class)->setLocale(Locale::Nl->value);

    expect(Fmt::number(1234567.5, 1))->toBe('1.234.567,5');
});

it('falls back to the locale marks it carries itself when ICU cannot', function (): void {
    app()->make(Translator::class)->setLocale('xx_XX_INVALID');

    expect(Fmt::number(1234567.5, 1))->toBe('1,234,567.5');
});

it('mirrors ICU grouping for the two locales the product anchors on', function (string $code): void {
    $locale = Locale::from($code);
    app()->make(Translator::class)->setLocale($code);

    expect(Fmt::number(1234567.5, 1))
        ->toBe(number_format(1234567.5, 1, $locale->decimalMark(), $locale->groupMark()));
})->with(['en', 'nl']);

it('never writes a digit group and a decimal with the same character', function (): void {
    foreach (Locale::cases() as $locale) {
        expect($locale->groupMark())->not->toBe($locale->decimalMark())
            ->and($locale->decimalMark())->toBe($locale === Locale::En ? '.' : ',');
    }
});

// The nav rail shortens a four-digit badge, and the shortening introduces a
// tenth where the raw count had none. PHP casts that float with a dot whatever
// the locale, so a Dutch reader met "1.2k" -- a dot being what Dutch groups
// thousands with -- beside a card correctly reading "5.701,66".
it('shortens a badge count with the marks the reader uses for a tenth', function (): void {
    app()->make(Translator::class)->setLocale(Locale::Nl->value);
    expect(Fmt::compactCount(1200))->toBe('1,2K');

    app()->make(Translator::class)->setLocale(Locale::En->value);
    expect(Fmt::compactCount(1200))->toBe('1.2K');
});

it('offers a tenth only when the shortened count has one', function (): void {
    app()->make(Translator::class)->setLocale(Locale::Nl->value);

    expect(Fmt::compactCount(999))->toBe('999')
        ->and(Fmt::compactCount(1000))->toBe('1K')
        ->and(Fmt::compactCount(12000))->toBe('12K')
        ->and(Fmt::compactCount(0))->toBe('0');
});

// CLDR keeps two significant digits in the short form, so a five-digit count
// loses the tenth a four-digit one keeps. Appending to a figure rounded to one
// decimal place would have written 12.3K where CLDR writes 12K.
it('keeps two significant digits, the way the short form does', function (): void {
    app()->make(Translator::class)->setLocale(Locale::En->value);

    expect(Fmt::compactCount(1234))->toBe('1.2K')
        ->and(Fmt::compactCount(9999))->toBe('10K')
        ->and(Fmt::compactCount(12345))->toBe('12K')
        ->and(Fmt::compactCount(150000))->toBe('150K')
        ->and(Fmt::compactCount(1500000))->toBe('1.5M');
});

// CLDR revises a short form between ICU releases, and the ICU this runs against
// is not the one on every machine: Ubuntu's and macOS's disagree about Italian
// today. Each entry records both answers and which one ships, so the guard
// passes wherever it runs without going quiet about the other twenty-five.
const COMPACT_FORMS_CLDR_MOVED = [
    'it' => [
        1000 => ['K', null],
        1000000 => ["\u{00A0}Mln", "\u{00A0}Mio"],
    ],
];

// The abbreviation is transcribed because the phone's ICU can only answer for
// English, and a table nothing checks is a table that drifts. ICU is asked here
// and has to agree for all 26 at both magnitudes.
it('abbreviates a thousand and a million the way CLDR abbreviates them', function (): void {
    $wrong = [];

    foreach (Locale::cases() as $locale) {
        $short = new NumberFormatter($locale->value, NumberFormatter::DECIMAL_COMPACT_SHORT);

        foreach ([1000 => $locale->compactThousands(), 1000000 => $locale->compactMillions()] as $magnitude => $transcribed) {
            $rendered = (string) $short->format($magnitude);

            // A locale CLDR gives no short form at this magnitude renders the
            // figure itself, which is never "1" followed by letters.
            $cldr = preg_match('/^1(\D*)$/u', $rendered, $matches) === 1 ? $matches[1] : null;

            if ($transcribed === $cldr) {
                continue;
            }

            // Both answers have to be ones CLDR has given, and the shipped one
            // has to be the first: a typo matches neither and still reports.
            $moved = COMPACT_FORMS_CLDR_MOVED[$locale->value][$magnitude] ?? null;

            if ($moved !== null && $transcribed === $moved[0] && in_array($cldr, $moved, true)) {
                continue;
            }

            $wrong[] = $locale->value.' at '.$magnitude.': transcribed '.var_export($transcribed, true)
                .', CLDR says '.var_export($cldr, true);
        }
    }

    expect($wrong)->toBe([], implode("\n", [
        'Modules/Core/Public/Enums/Locale.php has drifted from CLDR:',
        ...$wrong,
    ]));
});

// The allowance above must not become a place a locale is quietly dropped: an
// entry is only for a form CLDR itself moved, and the ICU running this has to
// still give one of the two answers recorded for it.
it('keeps no record of a moved short form that CLDR no longer gives', function (): void {
    $stale = [];

    foreach (COMPACT_FORMS_CLDR_MOVED as $code => $magnitudes) {
        $short = new NumberFormatter($code, NumberFormatter::DECIMAL_COMPACT_SHORT);

        foreach ($magnitudes as $magnitude => $answers) {
            $rendered = (string) $short->format($magnitude);
            $cldr = preg_match('/^1(\D*)$/u', $rendered, $matches) === 1 ? $matches[1] : null;

            if (! in_array($cldr, $answers, true)) {
                $stale[] = $code.' at '.$magnitude.': this ICU says '.var_export($cldr, true)
                    .', which is neither answer recorded';
            }
        }
    }

    expect($stale)->toBe([], implode("\n", [
        'These records no longer describe any answer CLDR gives here:',
        ...$stale,
    ]));
});

// A lower-case k written straight onto the digits was what every locale got.
// Norwegian is the only one of the twenty-six CLDR spells that way — French
// uses the same letter but keeps a no-break space before it, and English
// itself writes a capital K.
it('writes an abbreviation the reader language actually uses', function (): void {
    $english = [];

    foreach (Locale::cases() as $locale) {
        app()->make(Translator::class)->setLocale($locale->value);

        if ($locale !== Locale::Nb && preg_match('/\dk$/u', Fmt::compactCount(1200)) === 1) {
            $english[] = $locale->value;
        }
    }

    expect($english)->toBe([], 'These still shorten with English\'s own letter: '.implode(', ', $english));
});

it('reads the same with and without ICU in all twenty-six languages', function (): void {
    // What the phone renders against what the desktop beside it does. ICU
    // writes U+2212 in seven languages and rounds a half to even; the fallback
    // wrote the ASCII hyphen and rounded away from zero, so a negative figure
    // and a 1280-byte file each read differently on the two.
    $values = [0, 1, -1, 1234, -1234, 1234567, -1234567, -0.4, -0.5, 1.25, -1.25, 2.5, 1280 / 1024];
    $mismatches = [];

    foreach (Locale::cases() as $locale) {
        app()->make(Translator::class)->setLocale($locale->value);

        foreach ($values as $value) {
            foreach ([0, 1, 2] as $decimals) {
                $icu = Fmt::number($value, $decimals);
                $withoutIcu = Fmt::numberWithoutIcu($value, $decimals);

                if ($icu !== $withoutIcu) {
                    $mismatches[] = $locale->value.sprintf(' %s/%s: %s vs %s', $value, $decimals, $icu, $withoutIcu);
                }
            }
        }
    }

    expect($mismatches)->toBe([]);
});

it('writes the minus sign the reader\'s own language writes', function (): void {
    $typographic = [Locale::Et, Locale::Fi, Locale::Hr, Locale::Lt, Locale::Nb, Locale::Sl, Locale::Sv];

    foreach (Locale::cases() as $locale) {
        expect($locale->minusSign())->toBe(in_array($locale, $typographic, true) ? "\u{2212}" : '-');
    }

    app()->make(Translator::class)->setLocale(Locale::Sv->value);

    expect(Fmt::numberWithoutIcu(-1234))->toStartWith("\u{2212}")
        ->and(Fmt::numberWithoutIcu(-1234))->not->toContain('-');
});

// The same list of thresholds is declared twice: Settings reads it from the
// catalogue, and the drift editor renders it from the figure. fi's catalogue
// said "±1 %" because a guard forces the catalogue to follow CLDR, and the
// editor beside it rendered "±1%" for want of anything that knew.
it('spells a threshold the way the catalogue two screens away spells it', function (): void {
    $disagreed = [];

    foreach (Locale::cases() as $locale) {
        app()->make(Translator::class)->setLocale($locale->value);

        /** @var array<string, mixed> $settings */
        $settings = require base_path('Modules/Core/Resources/lang/'.$locale->value.'/settings.php');

        foreach (['1', '10', '25', '50'] as $threshold) {
            $catalog = $settings['drift']['options'][$threshold];
            $rendered = Fmt::percent((int) $threshold, sign: '±');

            if ($catalog !== $rendered) {
                $disagreed[] = $locale->value.' ['.$threshold.'] catalogue '.bin2hex((string) $catalog)
                    .' vs rendered '.bin2hex($rendered);
            }
        }
    }

    expect($disagreed)->toBe([], implode("\n", [
        'A reader meets these two spellings of the same threshold on two screens:',
        ...$disagreed,
    ]));
});

// Turkish writes the sign in front of the digits and thirteen locales keep a
// no-break space before it. ICU is asked here rather than transcribed, so a
// figure the reader is shown is checked against CLDR and not against the enum
// that Fmt::percent() and its guard both read.
it('places the percent sign where ICU places it, in every language', function (): void {
    $wrong = [];

    foreach (Locale::cases() as $locale) {
        app()->make(Translator::class)->setLocale($locale->value);

        $expected = (new NumberFormatter($locale->value, NumberFormatter::PERCENT))->format(0.42);

        if (Fmt::percent(42) !== $expected) {
            $wrong[] = $locale->value.' writes '.bin2hex(Fmt::percent(42)).', ICU writes '.bin2hex((string) $expected);
        }
    }

    expect($wrong)->toBe([], implode("\n", ['These disagree with CLDR:', ...$wrong]));
});
