<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Mt940Tag61Parser;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;

// BankAmountParser reads a three-decimal currency at its own scale and is
// tested for it. The :61: regex that feeds it was never widened to match, so
// the third digit fell out of the amount group and was swallowed by the
// customer reference -- taking the transaction-type code with it.
beforeEach(function (): void {
    $this->parser = $this->app->make(Mt940Tag61Parser::class);
});

it('reads all three fractional digits of a three-decimal currency', function (): void {
    $line = $this->parser->parse('2604170417D1000,505NTRFREF-KWD', 'KWD');

    expect($line->amountMinor)->toBe(-1000505)
        ->and($line->transactionTypeCode)->toBe('NTRF')
        ->and($line->customerReference)->toBe('REF-KWD');
});

it('still reads a two-decimal currency the way it always did', function (): void {
    $line = $this->parser->parse('2604170417D1000,50NTRFREF-EUR', 'EUR');

    expect($line->amountMinor)->toBe(-100050)
        ->and($line->transactionTypeCode)->toBe('NTRF')
        ->and($line->customerReference)->toBe('REF-EUR');
});

it('still refuses a fraction wider than the currency holds', function (): void {
    expect(fn (): mixed => $this->parser->parse('2604170417D1000,505NTRFREF-EUR', 'EUR'))
        ->toThrow(InvalidAmountException::class);
});

it('still reads the swift trailing comma of a zero-decimal currency', function (): void {
    $line = $this->parser->parse('2604170417D500000,NTRFREF-JPY', 'JPY');

    expect($line->amountMinor)->toBe(-500000)
        ->and($line->transactionTypeCode)->toBe('NTRF');
});
