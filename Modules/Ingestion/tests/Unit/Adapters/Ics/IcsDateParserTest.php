<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ingestion\Internal\Adapters\Ics\IcsDateParser;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;

// ICS prints `dd MMM.` on transaction lines and `dd MMMM YYYY` on the
// statement header; the catalogue lives in tests/fixtures/ics/ics-sample-1.md.

it('parses the dd-mm-yyyy Dutch numeric format', function (): void {
    $parser = new IcsDateParser;

    expect($parser->parse('12-04-2026')->equalTo(CarbonImmutable::create(2026, 4, 12)->startOfDay()))
        ->toBeTrue();
})->group('phase-3');

it('parses the j M Y abbreviated-month format with Dutch abbreviations (mei, mrt)', function (): void {
    $parser = new IcsDateParser;

    expect($parser->parse('5 mei 2026')->equalTo(CarbonImmutable::create(2026, 5, 5)->startOfDay()))
        ->toBeTrue();
    expect($parser->parse('15 mrt 2026')->equalTo(CarbonImmutable::create(2026, 3, 15)->startOfDay()))
        ->toBeTrue();
    expect($parser->parse('23 jan. 2026')->equalTo(CarbonImmutable::create(2026, 1, 23)->startOfDay()))
        ->toBeTrue();
})->group('phase-3');

it('rejects an invalid date string by throwing InvalidAmountException', function (): void {
    $parser = new IcsDateParser;

    expect(fn () => $parser->parse('not-a-date'))
        ->toThrow(InvalidAmountException::class);
})->group('phase-3');

it('returns a CarbonImmutable at startOfDay (00:00:00) to match the fingerprint composer', function (): void {
    $parser = new IcsDateParser;
    $parsed = $parser->parse('15 februari 2026');

    expect($parsed->hour)->toBe(0);
    expect($parsed->minute)->toBe(0);
    expect($parsed->second)->toBe(0);
})->group('phase-3');

it('does not mutate global locale state', function (): void {
    $parser = new IcsDateParser;
    $before = setlocale(LC_ALL, '0');

    $parser->parse('15 februari 2026');

    $after = setlocale(LC_ALL, '0');
    expect($after)->toBe($before);
})->group('phase-3');
