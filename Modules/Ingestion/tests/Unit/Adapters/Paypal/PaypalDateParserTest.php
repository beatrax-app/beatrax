<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalDateParser;
use Modules\Ingestion\Public\Exceptions\InvalidDateException;

/*
 * Coverage for the PayPal date parser.
 *
 * Every Datum cell in the PayPal Activity Download is rendered as
 * M/D/YYYY (US numeric date), even though the surrounding amount cells
 * use NL-locale comma decimals. Representative values from the
 * redacted fixture at
 * Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv: 4/1/2026,
 * 4/19/2026, 5/8/2026.
 *
 * ISO 8601 (yyyy-mm-dd) is accepted as a forward-compatibility fallback
 * for the case where a future PayPal export shifts to that shape; if it
 * does not, the EXTRA arm is dead-but-correct code.
 */

beforeEach(function (): void {
    $this->parser = new PaypalDateParser;
});

it('parses the m/d/Y US numeric format used by PayPal exports', function (): void {
    expect($this->parser->parse('4/1/2026')->equalTo(CarbonImmutable::create(2026, 4, 1)->startOfDay()))
        ->toBeTrue();
    expect($this->parser->parse('4/19/2026')->equalTo(CarbonImmutable::create(2026, 4, 19)->startOfDay()))
        ->toBeTrue();
    expect($this->parser->parse('5/8/2026')->equalTo(CarbonImmutable::create(2026, 5, 8)->startOfDay()))
        ->toBeTrue();
    expect($this->parser->parse('12/31/2026')->equalTo(CarbonImmutable::create(2026, 12, 31)->startOfDay()))
        ->toBeTrue();
})->group('phase-4');

it('parses the yyyy-mm-dd ISO format as a forward-compatibility fallback', function (): void {
    expect($this->parser->parse('2026-04-01')->equalTo(CarbonImmutable::create(2026, 4, 1)->startOfDay()))
        ->toBeTrue();
})->group('phase-4');

it('rejects an invalid date string by throwing InvalidDateException', function (): void {
    expect(fn () => $this->parser->parse('not-a-date'))
        ->toThrow(InvalidDateException::class);
})->group('phase-4');

it('rejects an empty date string', function (): void {
    expect(fn () => $this->parser->parse(''))
        ->toThrow(InvalidDateException::class);
})->group('phase-4');

it('returns a CarbonImmutable at startOfDay (00:00:00) to match the fingerprint composer', function (): void {
    $parsed = $this->parser->parse('4/1/2026');

    expect($parsed->hour)->toBe(0);
    expect($parsed->minute)->toBe(0);
    expect($parsed->second)->toBe(0);
})->group('phase-4');

it('does not mutate global locale state', function (): void {
    $before = setlocale(LC_ALL, '0');

    $this->parser->parse('4/1/2026');

    $after = setlocale(LC_ALL, '0');
    expect($after)->toBe($before);
})->group('phase-4');
