<?php

declare(strict_types=1);
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-locale-argument-passed-to-moneyformat
 */

// The server formats an amount for whoever is reading it — separators and
// symbol position both. The shared chart helper picked its money locale from
// the currency instead, so a German reader's /forecast axis drew "€ 4.928"
// beside "1.234,56 €" in every tile: one currency, two notations, one page.

/** @return string resources/js/app.js */
function chartAxisHelperSource(): string
{
    $path = base_path('resources/js/app.js');

    expect(is_file($path))->toBeTrue('resources/js/app.js is not readable from this Composer root');

    return (string) file_get_contents($path);
}

/** @return string the currency glyph ICU writes for this pair, digits and punctuation removed */
function chartAxisIcuGlyph(NumberFormatter $formatter, string $currency): string
{
    $rendered = (string) $formatter->formatCurrency(1, $currency);

    return (string) preg_replace('/[\d\s\x{00A0}\x{202F}.,\-\x{2212}]/u', '', $rendered);
}

it('takes the chart axis money locale from the page language, never from the currency', function (): void {
    $source = chartAxisHelperSource();

    // The locale is the first argument to the currency formatter the helper
    // installs on every y-axis.
    $call = PatternScan::first(
        '/new Intl\.NumberFormat\(\s*(?<locale>[^,]+),\s*\{\s*style: \'currency\'/',
        $source
    );

    expect($call)->not->toBe([], 'The chart helper no longer formats its axis as currency at all.');

    $argument = trim($call['locale']);

    // `tag` is <html lang>, which the layout renders from the same translator
    // locale Money::format() reads. Anything else and the two disagree.
    expect($argument)->toBe(
        'tag',
        "The axis locale must be the page language, the way Money::format() is.\nGot: ".$argument
    );

    expect(PatternScan::count('/MONEY_LOCALES|moneyLocale/i', $source))->toBe(
        0,
        'A currency-to-locale table is back in resources/js/app.js. The currency '.
        'says WHAT is drawn; the reader\'s locale says HOW, and a table keyed by '.
        'currency can only re-import the notation of whoever wrote it.'
    );
});

it('formats a chart axis exactly as Money formats the same amount, in every shipped language', function (): void {
    // ApexCharts calls Intl in the browser; ext-intl answers from the same ICU
    // data, so the axis string can be reproduced here. English-only ICU (the
    // mobile PHP build) cannot, and says so rather than failing on nl.
    // Dutch ICU writes 1234.5 as "1.234,5" — dot for groups, comma for the
    // fraction. An English-only build has no nl data, falls back to the root
    // locale and writes "1,234.5". Both carry a dot, so a predicate asking only
    // whether a dot is present skips on neither runtime, and the build this
    // guard exists to excuse got a failure instead. The whole string is what
    // tells the two apart.
    $probe = new NumberFormatter('nl', NumberFormatter::DECIMAL);
    if ((string) $probe->format(1234.5) !== '1.234,5') {
        $this->markTestSkipped('This runtime ships English-only ICU data, so nl cannot be reproduced here.');
    }

    // The glyphs Money carries itself. It has no per-locale currency names —
    // ICU writes EUR as "EUR" in Hungarian — so the glyph is compared by the
    // side it lands on and everything around it byte for byte.
    $ours = ['EUR' => '€', 'GBP' => '£', 'CHF' => 'CHF'];

    foreach (Locale::cases() as $locale) {
        app()->setLocale($locale->value);

        $formatter = new NumberFormatter($locale->value, NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);

        // The sign is compared rather than normalised away: seven locales sign
        // with U+2212, and both ICU-less paths (Money, Fmt) now carry exactly
        // ICU's list, so forcing a hyphen here would hide the two diverging.

        foreach ($ours as $currency => $glyph) {
            foreach ([492800, -492800] as $minor) {
                $axis = str_replace(
                    chartAxisIcuGlyph($formatter, $currency),
                    '¤',
                    (string) $formatter->formatCurrency($minor / Money::MINOR_UNITS_PER_MAJOR, $currency)
                );

                expect(str_replace($glyph, '¤', Money::ofMinor($minor, $currency)->formatWholeUnits()))->toBe(
                    $axis,
                    $currency.' reads one way on a '.$locale->value.' chart and another everywhere else on the page.'
                );
            }
        }
    }
});
