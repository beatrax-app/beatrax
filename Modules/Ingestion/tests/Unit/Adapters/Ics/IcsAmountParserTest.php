<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Ics\IcsAmountParser;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;

/*
 * Coverage for the nl_NL amount parser used by the ICS PDF adapter.
 * Empirical formats sourced from
 * Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md "Dutch amount
 * formats" section: comma decimal separator (`50,00`), period
 * thousands separator (`1.416,50`), `€ ` prefix for EUR amounts,
 * ISO code suffix for foreign-currency amounts (`50,00 USD`).
 */

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
