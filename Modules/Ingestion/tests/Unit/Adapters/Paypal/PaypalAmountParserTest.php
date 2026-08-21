<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Paypal\PaypalAmountParser;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;

// PayPal renders amounts in NL locale — comma decimal, and no visible
// thousands separator in any single-month export observed so far.

beforeEach(function (): void {
    $this->parser = new PaypalAmountParser;
});

it('parses signed and unsigned comma-decimal amounts to integer minor units', function (string $raw, int $expected): void {
    expect($this->parser->parseMinor($raw))->toBe($expected);
})->with([
    'negative two-decimal' => ['-12,99', -1299],
    'positive sign two-decimal' => ['+12,99', 1299],
    'unsigned two-decimal' => ['12,99', 1299],
    'integer-only construction — 0,29 must not float-round to 28' => ['0,29', 29],
    'small negative — -0,01' => ['-0,01', -1],
    'zero' => ['0,00', 0],
    'thousands-shaped value without separator' => ['1000,00', 100000],
    'trailing whitespace stripped' => ['-123,45 ', -12345],
    'leading whitespace stripped' => [' 99,99', 9999],
    'large value' => ['1234567,89', 123456789],
    'fixture sample — -9,27' => ['-9,27', -927],
    'fixture sample — -10,46' => ['-10,46', -1046],
])->group('phase-4');

it('rejects malformed amount strings', function (string $raw): void {
    expect(fn () => $this->parser->parseMinor($raw))
        ->toThrow(InvalidAmountException::class);
})->with([
    'period decimal (US locale — wrong adapter)' => ['12.99'],
    'words' => ['abc'],
    'empty string' => [''],
    'three decimals' => ['12,345'],
    'one decimal' => ['12,3'],
    'no decimal at all' => ['12'],
    'multiple decimals' => ['1,2,3'],
    'double sign' => ['--12,34'],
    'sign only' => ['-'],
    'internal space between sign and digits' => ['+ 1,23'],
    'internal space inside digit run' => ['1 0,00'],
])->group('phase-4');
