<?php

declare(strict_types=1);

use Modules\Ledger\Public\ValueObjects\Money;

/*
 * `Money::format()` takes no locale: the currency decides. EUR renders in the
 * Dutch convention (comma decimal, € then a non-breaking space); every other
 * currency renders the way a card statement does, in US English. An amount is
 * read against its own currency, not against the reader's language, and the
 * argument that used to allow otherwise is gone.
 */

it('formats EUR in the Dutch convention', function (): void {
    $formatted = Money::ofMinor(6886, 'EUR')->format();

    expect($formatted)->toContain('€');
    expect($formatted)->toContain('68,86');
})->group('phase-3');

it('formats USD in the US English convention', function (): void {
    $formatted = Money::ofMinor(7443, 'USD')->format();

    expect($formatted)->toContain('$');
    expect($formatted)->toContain('74.43');
})->group('phase-3');

it('formats negative USD with leading minus', function (): void {
    $formatted = Money::ofMinor(-7443, 'USD')->format();

    expect($formatted)->toContain('-');
    expect($formatted)->toContain('74.43');
    // Calm-aesthetic guard: never parentheses for negatives.
    expect($formatted)->not->toContain('(');
    expect($formatted)->not->toContain(')');
})->group('phase-3');

it('never renders a non-EUR amount in Dutch separators', function (): void {
    // The whole reason the locale argument was removed: nl_NL turns a dollar
    // amount into "US$ -1.245,67", which is not how anyone reads a card
    // statement in any of the app's 26 languages.
    expect(Money::ofMinor(-124567, 'USD')->format())->toBe('-$1,245.67');
    expect(Money::ofMinor(98750, 'GBP')->format())->toBe('£987.50');
})->group('phase-3');

/*
 * The no-ICU fallback. `formatToLocale()` builds a NumberFormatter, which the
 * mobile PHP build's ext-intl cannot always construct — its ICU ships locale
 * data for English only, and it reports the miss two ways: IntlException when
 * intl error-exceptions are enabled, ValueError from the constructor when it
 * rejects the locale outright. Every rendered amount in the product funnels
 * through format(), so an uncaught one is a 500 on any page showing money.
 * formatWithoutIcu() is public because the device condition cannot be
 * reproduced on a host with full ICU data, and a rendering nothing can reach
 * is a rendering nothing checks.
 */

it('renders EUR in the Dutch convention without ICU', function (): void {
    expect(Money::ofMinor(123456, 'EUR')->formatWithoutIcu())->toBe("€\u{00A0}1.234,56");
})->group('phase-3');

it('signs the digits rather than the symbol for EUR without ICU', function (): void {
    expect(Money::ofMinor(-123450, 'EUR')->formatWithoutIcu())->toBe("€\u{00A0}-1.234,50");
})->group('phase-3');

it('renders non-EUR in the US English convention without ICU', function (): void {
    expect(Money::ofMinor(-7443, 'USD')->formatWithoutIcu())->toBe('-$74.43');
})->group('phase-3');

it('falls back to the currency code for a currency with no symbol', function (): void {
    expect(Money::ofMinor(385000, 'CHF')->formatWithoutIcu())->toBe("CHF\u{00A0}3,850.00");
})->group('phase-3');

it('keeps the two-decimal scale without ICU', function (): void {
    // Whole amounts must not render as "€ 12" — a money column that
    // sometimes shows decimals and sometimes does not is harder to scan
    // than one that always does.
    expect(Money::ofMinor(1200, 'EUR')->formatWithoutIcu())->toBe("€\u{00A0}12,00");
})->group('phase-3');

it('renders identically with and without ICU', function (int $minor, string $currency): void {
    // The point of the fallback: mobile must not read differently from
    // desktop. This host has full ICU data, so format() takes the ICU path
    // and formatWithoutIcu() takes the transcribed one.
    expect(Money::ofMinor($minor, $currency)->formatWithoutIcu())
        ->toBe(Money::ofMinor($minor, $currency)->format());
})->with([
    [123456, 'EUR'],
    [-123450, 'EUR'],
    [5, 'EUR'],
    [123456789, 'EUR'],
    [-7443, 'USD'],
    [123450, 'GBP'],
    [385000, 'CHF'],
])->group('phase-3');

it('uses ICU when ICU is there rather than always taking the fallback', function (): void {
    // Guards the catch from widening into a silent swallow. The two paths
    // agree on every currency the product deals in, so the proof needs one
    // they cannot agree on: ICU knows the rupee sign, the transcribed symbol
    // table only carries the four currencies Currency declares.
    expect(Money::ofMinor(123456, 'INR')->format())->toBe("₹1,234.56");
    expect(Money::ofMinor(123456, 'INR')->formatWithoutIcu())->toBe("INR\u{00A0}1,234.56");
})->group('phase-3');
