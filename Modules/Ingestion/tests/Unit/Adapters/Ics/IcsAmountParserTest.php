<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Ics\IcsAmountParser;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;

// Every format below is copied from a real statement; the catalogue lives in
// tests/fixtures/ics/ics-sample-1.md under "Dutch amount formats".

it('parses a positive EUR amount with comma decimal: € 22,75 → 2275', function (): void {
    $parser = new IcsAmountParser;

    expect($parser->parse('€ 22,75'))->toBe(2275);
})->group('phase-3');

it('parses a negative EUR amount written with a minus prefix: -€ 22,75 → -2275', function (): void {
    $parser = new IcsAmountParser;

    expect($parser->parse('-€ 22,75'))->toBe(-2275);
})->group('phase-3');

it('parses a USD amount with the ISO symbol: $ 12,99 → 1299', function (): void {
    $parser = new IcsAmountParser;

    expect($parser->parse('$ 12,99'))->toBe(1299);
})->group('phase-3');

it('parses a Dutch thousands+decimal amount: 1.416,50 → 141650', function (): void {
    $parser = new IcsAmountParser;

    expect($parser->parse('1.416,50'))->toBe(141650);
    expect($parser->parse('€ 1.416,50'))->toBe(141650);
    expect($parser->parse('€ 2.500,00'))->toBe(250000);
})->group('phase-3');

it('rejects a malformed amount string by throwing InvalidAmountException', function (): void {
    $parser = new IcsAmountParser;

    expect(fn () => $parser->parse('not-an-amount'))
        ->toThrow(InvalidAmountException::class);
})->group('phase-3');

it('does not mutate global locale state', function (): void {
    $parser = new IcsAmountParser;
    $before = setlocale(LC_ALL, '0');

    $parser->parse('€ 22,75');

    $after = setlocale(LC_ALL, '0');
    expect($after)->toBe($before);
})->group('phase-3');

it('strips every glyph Money knows, not a list of its own', function (string $raw): void {
    $parser = new IcsAmountParser;

    expect($parser->parse($raw))->toBe(1250);
})->with([
    'euro' => ['€ 12,50'],
    'dollar' => ['$ 12,50'],
    'pound' => ['£ 12,50'],
    'yen' => ['¥ 12,50'],
    'iso code suffix' => ['12,50 USD'],
])->group('phase-3');

it('keeps the trailing-minus form the summary blocks write', function (): void {
    $parser = new IcsAmountParser;

    expect($parser->parse('22,75-'))->toBe(-2275);
})->group('phase-3');

// The parser delegates the string→minor step to MoneyInput but keeps the ICS
// grammar in front of it. A statement writes comma-decimal with two fractional
// digits and nothing else, so the English convention and a bare integer run
// stay refusals rather than becoming a hundredfold misreading.
it('refuses a convention no ICS statement writes', function (string $raw): void {
    $parser = new IcsAmountParser;

    expect(fn (): int => $parser->parse($raw))->toThrow(InvalidAmountException::class);
})->with([
    'English thousands+decimal' => ['1,234.56'],
    'bare integer run' => ['1234'],
    'period decimal' => ['12.50'],
    'one fractional digit' => ['12,5'],
    'exchange-rate precision' => ['1,14390'],
])->group('phase-3');

// MoneyInput::MAX_MINOR now bounds the figure, where the hand-rolled multiply
// ran to a float and a TypeError. No card statement reaches nine figures.
it('refuses a magnitude past what MoneyInput will carry', function (string $raw): void {
    $parser = new IcsAmountParser;

    expect(fn (): int => $parser->parse($raw))->toThrow(InvalidAmountException::class);
})->with([
    'ten whole digits' => ['1000000000,00'],
    'twelve whole digits' => ['999999999999,99'],
    'sixteen whole digits' => ['1234567890123456,78'],
])->group('phase-3');

it('carries the largest figure MoneyInput accepts', function (): void {
    $parser = new IcsAmountParser;

    expect($parser->parse('999999999,99'))->toBe(99999999999);
})->group('phase-3');
