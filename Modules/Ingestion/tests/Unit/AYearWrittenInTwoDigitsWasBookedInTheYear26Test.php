<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Tests\Support\CsvHandedToTheApp;

// PHP's 'Y' matches one to four digits and raises no warning for the short
// reading, so a dialect declaring a four-digit year still accepted a two-digit
// one. SafeDate::dayOrNull() refused the same string on its round-trip, so the
// two readings of "is this the day somebody meant" disagreed.
it('refuses a two-digit year offered to a four-digit-year format', function (): void {
    expect(SafeDate::fromFormatOrNull('!Y-m-d', '26-05-01'))->toBeNull();
    expect(SafeDate::dayOrNull('26-05-01'))->toBeNull();
});

it('still reads the four-digit year the format asks for', function (): void {
    $parsed = SafeDate::fromFormatOrNull('!Y-m-d', '2026-05-01');

    expect($parsed)->toBeInstanceOf(CarbonImmutable::class);
    expect($parsed?->toDateString())->toBe('2026-05-01');
});

// MT940's sliding window is a two-digit year a format declares as two digits,
// which is a different thing from a four-digit one that came up short.
it('leaves the two-digit-year formats that declare themselves alone', function (): void {
    expect(SafeDate::fromFormatOrNull('!ymd', '260501')?->toDateString())->toBe('2026-05-01');
});

it('refuses the row rather than booking it two thousand years ago', function (): void {
    $body = CsvHandedToTheApp::N26_HEADER."\n"
        .'"26-05-01","26-05-01","REWE","DE89370400440532013000","Presentment","Groceries","Main Account","-23.45","","",""'."\n";

    // Before: postedAt was 0026-05-01 and the row imported without a word.
    expect(fn (): array => CsvHandedToTheApp::parsedThrough(CsvPresetRegistry::N26, $body))
        ->toThrow(InvalidAmountException::class, "Cannot parse date '26-05-01'");
});
