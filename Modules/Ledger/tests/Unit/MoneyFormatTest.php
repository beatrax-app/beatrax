<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\Locale;
use Modules\Ledger\Public\ValueObjects\Money;

it('formats EUR in the Dutch convention for a Dutch reader', function (): void {
    app()->setLocale(Locale::Nl->value);

    expect(Money::ofMinor(6886, 'EUR')->format())->toBe("€\u{00A0}68,86");
})->group('phase-3');

it('formats USD in the US English convention for an English reader', function (): void {
    $formatted = Money::ofMinor(7443, 'USD')->format();

    expect($formatted)->toContain('$');
    expect($formatted)->toContain('74.43');
})->group('phase-3');

it('formats negative USD with leading minus', function (): void {
    $formatted = Money::ofMinor(-7443, 'USD')->format();

    expect($formatted)->toContain('-');
    expect($formatted)->toContain('74.43');
    // Negatives never render in parentheses.
    expect($formatted)->not->toContain('(');
    expect($formatted)->not->toContain(')');
})->group('phase-3');

it('reads a foreign currency in the reader\'s own separators', function (): void {
    // The currency says WHAT is rendered; the reader's locale says HOW. A
    // Dutch reader reads dollars in Dutch grouping, the way every other
    // number on the page is grouped.
    app()->setLocale(Locale::Nl->value);

    expect(Money::ofMinor(-124567, 'USD')->format())->toBe("US$\u{00A0}-1.245,67");

    app()->setLocale(Locale::En->value);

    expect(Money::ofMinor(-124567, 'USD')->format())->toBe('-$1,245.67');
})->group('phase-3');

it('puts the symbol where the reader\'s language puts it', function (string $locale, string $expected): void {
    // Currency deciding the language served every eurozone reader the Dutch
    // "€ 1.234,56" — symbol first — where all but Dutch and Portuguese write
    // it last, and Turkish writes it first with no space at all.
    app()->setLocale($locale);

    expect(Money::ofMinor(123456, 'EUR')->format())->toBe($expected);
})->with([
    ['en', '€1,234.56'],
    ['nl', "€\u{00A0}1.234,56"],
    ['de', "1.234,56\u{00A0}€"],
    ['fr', "1\u{202F}234,56\u{00A0}€"],
    ['pt', "€\u{00A0}1.234,56"],
    ['tr', '€1.234,56'],
    ['sv', "1\u{00A0}234,56\u{00A0}€"],
])->group('phase-3');

it('puts the symbol in the same place without ICU', function (string $locale, string $expected): void {
    // What both phones actually render: the mobile build's ICU has no data
    // for any of these, so this is the only path that runs there.
    app()->setLocale($locale);

    expect(Money::ofMinor(123456, 'EUR')->formatWithoutIcu())->toBe($expected);
})->with([
    ['en', '€1,234.56'],
    ['nl', "€\u{00A0}1.234,56"],
    ['de', "1.234,56\u{00A0}€"],
    ['fr', "1\u{202F}234,56\u{00A0}€"],
    ['pt', "€\u{00A0}1.234,56"],
    ['tr', '€1.234,56'],
    ['sv', "1\u{00A0}234,56\u{00A0}€"],
])->group('phase-3');

it('signs the digits rather than the symbol for Dutch without ICU', function (): void {
    app()->setLocale(Locale::Nl->value);

    expect(Money::ofMinor(-123450, 'EUR')->formatWithoutIcu())->toBe("€\u{00A0}-1.234,50");
})->group('phase-3');

it('signs the whole amount everywhere else without ICU', function (string $locale, string $expected): void {
    app()->setLocale($locale);

    expect(Money::ofMinor(-123450, 'EUR')->formatWithoutIcu())->toBe($expected);
})->with([
    ['en', '-€1,234.50'],
    ['pt', "-€\u{00A0}1.234,50"],
    ['de', "-1.234,50\u{00A0}€"],
])->group('phase-3');

it('groups with a non-breaking space without splitting it in half', function (): void {
    // The chunking reverses the digit string, and reversing the assembled
    // one instead turned every multi-byte mark into mojibake — eleven
    // locales group with U+00A0 and French with U+202F.
    app()->setLocale(Locale::Fi->value);

    expect(Money::ofMinor(123456789, 'EUR')->formatWithoutIcu())->toBe("1\u{00A0}234\u{00A0}567,89\u{00A0}€");
})->group('phase-3');

it('falls back to the currency code for a currency with no symbol', function (): void {
    expect(Money::ofMinor(385000, 'CHF')->formatWithoutIcu())->toBe("CHF\u{00A0}3,850.00");
})->group('phase-3');

it('keeps the two-decimal scale without ICU', function (): void {
    // A money column that sometimes shows decimals is harder to scan.
    app()->setLocale(Locale::Nl->value);

    expect(Money::ofMinor(1200, 'EUR')->formatWithoutIcu())->toBe("€\u{00A0}12,00");
})->group('phase-3');

it('renders identically with and without ICU', function (string $locale, int $minor, string $currency): void {
    // The host has full ICU data, so format() takes the ICU path while the
    // fallback takes the transcribed one; mobile must not read differently.
    // Only pairs whose glyph ICU and the SYMBOLS table agree on — ICU writes
    // USD as "US$" in Dutch and EUR as "EUR" in Hungarian, and the class
    // carries one symbol per currency rather than CLDR's per-locale names.
    app()->setLocale($locale);

    expect(Money::ofMinor($minor, $currency)->formatWithoutIcu())
        ->toBe(Money::ofMinor($minor, $currency)->format());
})->with([
    ['en', 123456, 'EUR'],
    ['en', -123450, 'EUR'],
    ['en', 5, 'EUR'],
    ['en', 123456789, 'EUR'],
    ['en', -7443, 'USD'],
    ['en', 123450, 'GBP'],
    ['en', 385000, 'CHF'],
    ['nl', 123456, 'EUR'],
    ['nl', -123450, 'EUR'],
    ['nl', 123456789, 'EUR'],
    ['nl', 123450, 'GBP'],
    ['nl', 385000, 'CHF'],
    ['de', 123456, 'EUR'],
    ['de', -123450, 'EUR'],
    ['de', -7443, 'USD'],
    ['de', 123450, 'GBP'],
    ['de', 385000, 'CHF'],
    ['tr', 123456, 'EUR'],
    ['tr', -123450, 'EUR'],
    ['tr', -7443, 'USD'],
    ['pt', 123456, 'EUR'],
    ['pt', -123450, 'EUR'],
    ['pt', 385000, 'CHF'],
    ['fr', 123456, 'EUR'],
    ['fr', -123450, 'EUR'],
])->group('phase-3');

it('uses ICU when ICU is there rather than always taking the fallback', function (): void {
    // Guards the catch against widening into a silent swallow: the paths agree
    // on every currency the product deals in, so the proof needs one they
    // cannot — ICU knows the rupee sign, the transcribed table does not.
    expect(Money::ofMinor(123456, 'INR')->format())->toBe('₹1,234.56');
    expect(Money::ofMinor(123456, 'INR')->formatWithoutIcu())->toBe("INR\u{00A0}1,234.56");
})->group('phase-3');

it('reads an unrecognised locale as English rather than throwing mid-render', function (): void {
    // A translator primed with something the app does not ship must not take
    // a page that shows money down with it.
    app()->setLocale('xx');

    expect(Money::ofMinor(123456, 'EUR')->formatWithoutIcu())->toBe('€1,234.56');
})->group('phase-3');

it('renders identically with and without ICU in all twenty-six languages', function (): void {
    // The dataset above reaches six languages, none of which ICU gives a
    // typographic minus. CHF has no glyph on either side, so it is safe
    // everywhere; EUR is skipped where ICU writes the code, this class
    // carrying one symbol per currency rather than CLDR's per-locale names.
    $codeOnlyEuro = [Locale::Hu, Locale::Ro, Locale::Uk];
    $amounts = [123456, -123456, -123450, -5, 5, 0, -123456789];
    $mismatches = [];

    foreach (Locale::cases() as $locale) {
        app()->setLocale($locale->value);

        foreach (in_array($locale, $codeOnlyEuro, true) ? ['CHF'] : ['EUR', 'CHF'] as $currency) {
            foreach ($amounts as $minor) {
                $money = Money::ofMinor($minor, $currency);

                if ($money->formatWithoutIcu() !== $money->format()) {
                    $mismatches[] = $locale->value." {$minor} {$currency}: "
                        .$money->format().' vs '.$money->formatWithoutIcu();
                }
            }
        }
    }

    expect($mismatches)->toBe([]);
})->group('phase-3');

it('writes the minus sign the reader\'s own language writes', function (): void {
    // Seven locales write U+2212 where the fallback wrote the ASCII hyphen, so
    // every negative amount read differently on a phone than on the desktop
    // beside it. CHF because its glyph is the code on both paths in all of them.
    foreach (Locale::cases() as $locale) {
        app()->setLocale($locale->value);
        $formatted = Money::ofMinor(-123456, 'CHF')->formatWithoutIcu();

        expect($formatted)->toContain($locale->minusSign())
            ->and($formatted)->toBe(Money::ofMinor(-123456, 'CHF')->format());

        if ($locale->minusSign() !== '-') {
            expect($formatted)->not->toContain('-');
        }
    }
})->group('phase-3');
