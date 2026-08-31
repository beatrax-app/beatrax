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
    expect(Fmt::compactCount(1200))->toBe('1,2k');

    app()->make(Translator::class)->setLocale(Locale::En->value);
    expect(Fmt::compactCount(1200))->toBe('1.2k');
});

it('offers a tenth only when the shortened count has one', function (): void {
    app()->make(Translator::class)->setLocale(Locale::Nl->value);

    expect(Fmt::compactCount(999))->toBe('999')
        ->and(Fmt::compactCount(1000))->toBe('1k')
        ->and(Fmt::compactCount(12000))->toBe('12k')
        ->and(Fmt::compactCount(0))->toBe('0');
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
                    $mismatches[] = $locale->value." {$value}/{$decimals}: {$icu} vs {$withoutIcu}";
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
