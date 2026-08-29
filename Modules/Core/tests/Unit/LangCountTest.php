<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\Lang;

// Laravel fills :count with the raw integer it selected the plural form from,
// so a finished import told a Dutch reader "1200 transacties geimporteerd"
// while every amount on the same screen was grouped "5.701,66". The seam fills
// it instead, and the selection still runs on the integer.

it('groups the count it puts in a counted line', function (string $code, string $expected): void {
    app()->make(Translator::class)->setLocale($code);

    expect(Lang::choice('notifications::copy.body.import_finished', 1200))
        ->toContain($expected);
})->with([
    ['en', '1,200'],
    ['nl', '1.200'],
]);

it('still picks the plural form from the number, not from the grouped text', function (): void {
    app()->make(Translator::class)->setLocale(Locale::En->value);

    expect(Lang::choice('notifications::copy.body.import_finished', 1))
        ->toBe('1 transaction imported.')
        ->and(Lang::choice('notifications::copy.body.import_finished', 2))
        ->toBe('2 transactions imported.');
});
