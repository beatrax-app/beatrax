<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\BankAmountParser;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;

beforeEach(function (): void {
    $this->parser = new BankAmountParser;
});

it('parses signed and unsigned period-decimal amounts to integer minor units', function (string $raw, int $expected): void {
    expect($this->parser->parseMinor($raw))->toBe($expected);
})->with([
    'negative two-decimal' => ['-12.34', -1234],
    'positive sign two-decimal' => ['+12.34', 1234],
    'unsigned two-decimal' => ['12.34', 1234],
    'integer-only construction — 0.29 must not float-round to 28' => ['0.29', 29],
    'small negative — 0.01' => ['-0.01', -1],
    'zero' => ['0.00', 0],
    'thousands without separator' => ['1000.00', 100000],
    'trailing whitespace stripped' => ['-123.45 ', -12345],
    'leading whitespace stripped' => [' 99.99', 9999],
    'large value' => ['1234567.89', 123456789],
]);

it('rejects malformed amount strings', function (string $raw): void {
    expect(fn () => $this->parser->parseMinor($raw))
        ->toThrow(InvalidAmountException::class);
})->with([
    'European comma decimal' => ['12,34'],
    'words' => ['twelve'],
    'empty string' => [''],
    'three decimals' => ['12.345'],
    'one decimal' => ['12.3'],
    'no decimal at all' => ['12'],
    'multiple decimals' => ['1.2.3'],
    'double sign' => ['--12.34'],
    'sign only' => ['-'],
    'internal space between sign and digits' => ['+ 1.23'],
    'internal space inside digit run' => ['1 0.00'],
    'NBSP between sign and digits' => ["+\u{A0}1.23"],
]);

// The three shapes MT940 writes that parseMinor() refuses on its own. The
// normalisation used to live twice, in Mt940Adapter and Mt940Tag61Parser.
it('normalises the MT940 amount shapes before parsing them', function (string $raw, int $expected): void {
    expect($this->parser->parseMt940Minor($raw))->toBe($expected);
})->with([
    'comma decimal' => ['1000,00', 100000],
    'comma decimal, one fractional digit' => ['1000,5', 100050],
    'no decimal at all' => ['1000', 100000],
    'zero without a decimal' => ['0', 0],
    'period decimal passes straight through' => ['12.34', 1234],
    'period decimal, one fractional digit' => ['12.3', 1230],
    'signed comma decimal' => ['-12,34', -1234],
]);

it('still refuses an MT940 amount that is not a number', function (string $raw): void {
    expect(fn (): int => $this->parser->parseMt940Minor($raw))
        ->toThrow(InvalidAmountException::class);
})->with([
    'words' => ['twelve'],
    'empty string' => [''],
    'three decimals' => ['12,345'],
    'two separators' => ['1.234,56'],
]);
