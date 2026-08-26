<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\BankAmountParser;
use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAmountParser;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalAmountParser;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

// GenericCsvAmountParser refuses a whole part longer than MAX_WHOLE_DIGITS
// because the minor-unit multiplication leaves the 64-bit range and the int
// return type then raises a TypeError. Its two siblings parse the same money
// out of the same bank exports and had no such ceiling.

$tooLong = str_repeat('9', MoneyInput::MAX_WHOLE_DIGITS + 1);
$longest = str_repeat('9', MoneyInput::MAX_WHOLE_DIGITS);

it('refuses a period-decimal amount past MAX_WHOLE_DIGITS instead of overflowing', function () use ($tooLong): void {
    expect(fn () => (new BankAmountParser)->parseMinor($tooLong.'.99'))
        ->toThrow(InvalidAmountException::class);
});

it('refuses an MT940 amount past MAX_WHOLE_DIGITS instead of overflowing', function () use ($tooLong): void {
    expect(fn () => (new BankAmountParser)->parseMt940Minor($tooLong.',99'))
        ->toThrow(InvalidAmountException::class);
});

it('refuses a PayPal amount past MAX_WHOLE_DIGITS instead of overflowing', function () use ($tooLong): void {
    expect(fn () => (new PaypalAmountParser)->parseMinor($tooLong.',99'))
        ->toThrow(InvalidAmountException::class);
});

it('still parses the longest whole part the ledger can hold', function () use ($longest): void {
    $expected = ((int) $longest) * 100 + 99;

    expect((new BankAmountParser)->parseMinor($longest.'.99'))->toBe($expected)
        ->and((new BankAmountParser)->parseMt940Minor($longest.',99'))->toBe($expected)
        ->and((new PaypalAmountParser)->parseMinor($longest.',99'))->toBe($expected)
        ->and((new GenericCsvAmountParser)->parseMinor($longest.'.99', '.'))->toBe($expected);
});

it('answers an over-range amount the same way whichever export it arrived in', function () use ($tooLong): void {
    $thrown = [];

    foreach ([
        'asn-csv' => fn () => (new BankAmountParser)->parseMinor($tooLong.'.99'),
        'mt940' => fn () => (new BankAmountParser)->parseMt940Minor($tooLong.',99'),
        'paypal-csv' => fn () => (new PaypalAmountParser)->parseMinor($tooLong.',99'),
        'generic-csv' => fn () => (new GenericCsvAmountParser)->parseMinor($tooLong.'.99', '.'),
    ] as $format => $parse) {
        try {
            $parse();
            $thrown[$format] = 'nothing';
        } catch (Throwable $e) {
            $thrown[$format] = $e::class;
        }
    }

    expect(array_unique(array_values($thrown)))->toBe(
        [InvalidAmountException::class],
        'Each format must refuse an over-range amount the same way, got: '.json_encode($thrown),
    );
});
