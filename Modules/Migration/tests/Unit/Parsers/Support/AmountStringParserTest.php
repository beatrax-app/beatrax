<?php

declare(strict_types=1);

use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Migration\Internal\Parsers\Support\AmountStringParser;

it('AmountStringParser: matches EnvelopeWriter::parseAmount() on the shared Dutch + plain sample set', function (): void {
    $parser = new AmountStringParser;
    $envelopeWriter = app(EnvelopeWriter::class);

    $samples = [
        '45.00',
        '0.00',
        '',
        '1.234,56',
        '1234.56',
        '  20.00  ',
        '15,00',
        '100',
        'not-a-number',
        '999999999999.99',
    ];

    foreach ($samples as $sample) {
        expect($parser->parse($sample))->toBe($envelopeWriter->parseAmount($sample), "mismatch for input '{$sample}'");
    }
});

it('AmountStringParser: a blank or zero-valued cell resolves to null (never zero)', function (): void {
    $parser = new AmountStringParser;

    expect($parser->parse(''))->toBeNull();
    expect($parser->parse('0.00'))->toBeNull();
    expect($parser->parse('0,00'))->toBeNull();
});

it('AmountStringParser: parses plain and Dutch-grouped decimals to the correct minor amount', function (): void {
    $parser = new AmountStringParser;

    expect($parser->parse('45.00'))->toBe(4500);
    expect($parser->parse('45,00'))->toBe(4500);
    expect($parser->parse('1.234,56'))->toBe(123456);
    expect($parser->parse('1,234.56'))->toBe(123456);
});
