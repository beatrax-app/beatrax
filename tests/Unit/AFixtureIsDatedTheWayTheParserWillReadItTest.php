<?php

declare(strict_types=1);

use App\Fixtures\MonthShift;
use App\Fixtures\Mt940Rebaser;
use Carbon\CarbonImmutable;
use Modules\Ingestion\Internal\Adapters\Banking\Mt940Tag61Parser;

// A :61: date carries a two-digit year, so the century it lands in is a
// function of the wall clock. Pinned to 2026, the roll sits at yy = 77.
beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-30 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function swiftTag61(string $yymmdd, string $entryMonthDay = ''): string
{
    return $yymmdd.$entryMonthDay.'D100,00NTRFREF-1';
}

// The rebaser exists only to produce files the parser will read. Any two-digit
// year it dates differently from the parser is a fixture that imports as a
// different statement than the one the rebase was asked for.
it('reads a two-digit SWIFT year the way the parser will read it back', function (string $yy, string $expected): void {
    $line = swiftTag61($yy.'1231');

    $rebaserRead = app(Mt940Rebaser::class)->newestDate(':61:'.$line);
    $parserRead = app(Mt940Tag61Parser::class)->parse($line)->valueDate;

    expect($rebaserRead?->toDateString())->toBe($expected)
        ->and($parserRead->toDateString())->toBe($expected);
})->with([
    'this year' => ['26', '2026-12-31'],
    'the last year still ahead' => ['76', '2076-12-31'],
    'the first year the century rolls back' => ['77', '1977-12-31'],
    'the far end of the sliding window' => ['99', '1999-12-31'],
]);

// The entry date carries no year at all, so the rebaser has to rebuild the one
// the parser infers before it can shift it. A leap February is the case that
// separates the two possible rebuilds: 2027-12-29 shifted two months lands on
// the 29th, 2028-12-29 lands on the 28th.
it('rebuilds a year-crossing entry date on the rule the parser infers it with', function (): void {
    $source = ':61:'.swiftTag61('280131', '1229');

    $rebased = app(Mt940Rebaser::class)->rebase($source, MonthShift::of(2));

    $parsed = app(Mt940Tag61Parser::class)->parse(substr($rebased->contents, strlen(':61:')));

    expect($parsed->valueDate->toDateString())->toBe('2028-03-31')
        ->and($parsed->entryDate?->toDateString())->toBe('2028-02-29');
});
