<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Asn\AsnAmountParser;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;

beforeEach(function (): void {
    $this->parser = new AsnAmountParser;
});

it('parses signed and unsigned period-decimal amounts to integer minor units', function (string $raw, int $expected): void {
    expect($this->parser->parseMinor($raw))->toBe($expected);
})->with([
    'negative two-decimal' => ['-12.34', -1234],
    'positive sign two-decimal' => ['+12.34', 1234],
    'unsigned two-decimal' => ['12.34', 1234],
    'Pitfall 1 — 0.29 must not float-round to 28' => ['0.29', 29],
    'small negative — 0.01' => ['-0.01', -1],
    'zero' => ['0.00', 0],
    'thousands without separator' => ['1000.00', 100000],
    'trailing whitespace stripped' => ['-123.45 ', -12345],
    'leading whitespace stripped' => [' 99.99', 9999],
    'internal space between sign and digits' => ['+ 1.23', 123],
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
]);
