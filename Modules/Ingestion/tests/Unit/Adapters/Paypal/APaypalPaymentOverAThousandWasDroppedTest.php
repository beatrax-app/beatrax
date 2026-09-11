<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Paypal\PaypalAmountParser;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;

// PayPal renders NL locale, where the period groups thousands. The two sibling
// parsers in this repo both strip it -- IcsAmountParser with str_replace and
// GenericCsvAmountParser off its preset -- and this one passed it through to a
// regex that admits only digits and a comma, so any payment of a thousand or
// more was refused and its row dropped whole.
beforeEach(function (): void {
    $this->parser = new PaypalAmountParser;
});

it('reads a grouped amount at the locale paypal writes', function (string $raw, int $expected): void {
    expect($this->parser->parseMinor($raw))->toBe($expected);
})->with([
    'one thousand' => ['1.234,56', 123456],
    'negative one thousand' => ['-1.234,56', -123456],
    'a million' => ['1.234.567,89', 123456789],
    'exactly a thousand' => ['1.000,00', 100000],
]);

it('reads a grouped zero-decimal amount at its own scale', function (): void {
    expect($this->parser->parseMinor('1.000', 'JPY'))->toBe(1000);
});

it('still refuses a period that groups nothing', function (string $raw): void {
    expect(fn (): int => $this->parser->parseMinor($raw))->toThrow(InvalidAmountException::class);
})->with([
    'us-locale decimal' => ['12.34'],
    'groups of two' => ['1.23.456,78'],
    'a trailing group that is short' => ['1.23,45'],
]);

it('still reads an ungrouped amount the way it always did', function (): void {
    expect($this->parser->parseMinor('1234567,89'))->toBe(123456789)
        ->and($this->parser->parseMinor('0,29'))->toBe(29);
});
