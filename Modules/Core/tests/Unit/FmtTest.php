<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\Fmt;

/*
 * Fmt::number formats through ext-intl, which the mobile PHP build ships with
 * ICU data for English only — so on device NumberFormatter cannot be built for
 * any of the other twenty-five languages, and the four views that call Fmt
 * would 500 the moment the reader switched language. A locale ICU refuses
 * stands in for that condition, which a host with full ICU data cannot
 * otherwise reproduce.
 */

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
