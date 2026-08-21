<?php

declare(strict_types=1);
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-locale-argument-passed-to-moneyformat
 */

// The server renders EUR the Dutch way to an English reader because an amount
// is read against its own currency. The shared chart helper formatted its
// y-axis for <html lang> instead, so /forecast drew "€4,928" beside the
// dashboard's "€ 1.727,38" — one currency, two notations, one page apart.

/** @return string resources/js/app.js */
function chartAxisHelperSource(): string
{
    $path = base_path('resources/js/app.js');

    expect(is_file($path))->toBeTrue('resources/js/app.js is not readable from this Composer root');

    return (string) file_get_contents($path);
}

/** @return array<string, string> the currency-to-locale table the helper hands Intl */
function chartAxisMoneyLocales(): array
{
    $found = preg_match(
        '/BEATRAX_MONEY_LOCALES\s*=\s*\{(?<body>[^}]*)\}/',
        chartAxisHelperSource(),
        $table
    );

    expect($found)->toBe(
        1,
        'resources/js/app.js declares no BEATRAX_MONEY_LOCALES table, so nothing anchors '.
        'the chart axis to the currency it is drawing.'
    );

    preg_match_all('/(?<key>[A-Z]{3,7})\s*:\s*[\'"](?<locale>[a-zA-Z-]+)[\'"]/', $table['body'], $entries, PREG_SET_ORDER);

    $locales = [];
    foreach ($entries as $entry) {
        $locales[$entry['key']] = $entry['locale'];
    }

    expect(array_key_exists('DEFAULT', $locales))->toBeTrue('The table needs a DEFAULT for a currency it does not name.');

    return $locales;
}

it('picks the chart axis money locale from the currency, never from the page language', function (): void {
    // The locale is the first argument to the currency formatter the helper
    // installs on every y-axis.
    $found = preg_match(
        '/new Intl\.NumberFormat\(\s*(?<locale>[^,]+),\s*\{\s*style: \'currency\'/',
        chartAxisHelperSource(),
        $call
    );

    expect($found)->toBe(1, 'The chart helper no longer formats its axis as currency at all.');

    $argument = trim($call['locale']);

    expect(str_contains($argument, 'currency'))->toBeTrue(
        "The axis locale must be derived from the currency being drawn.\nGot: ".$argument
    );

    // `tag` is <html lang>. Money is anchored to its currency, not to the
    // interface language, and this is the argument that put two notations for
    // one currency on one page.
    expect(str_contains($argument, 'tag'))->toBeFalse(
        "The axis locale still comes from the page language.\nGot: ".$argument
    );
});

it('formats a chart axis exactly as Money formats the same currency', function (): void {
    // ApexCharts calls Intl in the browser; ext-intl answers from the same ICU
    // data, so the axis string can be reproduced here. English-only ICU (the
    // mobile PHP build) cannot, and says so rather than failing on nl_NL.
    $probe = new NumberFormatter('nl_NL', NumberFormatter::DECIMAL);
    if (! str_contains((string) $probe->format(1234.5), '.')) {
        $this->markTestSkipped('This runtime ships English-only ICU data, so nl_NL cannot be reproduced here.');
    }

    $locales = chartAxisMoneyLocales();

    // Whole units, matching the helper's maximumFractionDigits: 0.
    foreach ([['EUR', 492800, 4928], ['USD', 492800, 4928], ['GBP', 100000, 1000], ['SEK', 250000, 2500]] as [$currency, $minor, $units]) {
        $formatter = new NumberFormatter(
            str_replace('-', '_', $locales[$currency] ?? $locales['DEFAULT']),
            NumberFormatter::CURRENCY
        );
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);

        expect($formatter->formatCurrency($units, $currency))->toBe(
            Money::ofMinor($minor, $currency)->formatWholeUnits(),
            $currency.' reads one way on the chart and another everywhere else.'
        );
    }
});
