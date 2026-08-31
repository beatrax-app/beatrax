<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\BankAmountParser;
use Modules\Ingestion\Internal\Adapters\Banking\Mt940Adapter;
use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAdapter;
use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAmountParser;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalAmountParser;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ledger\Public\Enums\Currency;

beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };
});

// A yen has no minor unit, so the two-decimal assumption every parse boundary
// made turned ¥1.000 into ¥100.000 at the seam where the file is read. This
// covers the three parsers that read a FILE; the bank-API path through
// EnableBankingSourceAdapter has its own boundary and is not exercised here.
it('reads a yen CSV cell at the yen scale', function (): void {
    $parser = new GenericCsvAmountParser;

    expect($parser->parseMinor('-1000', '.', Currency::Jpy->value))->toBe(-1000)
        ->and($parser->parseMinor('50000', '.', Currency::Jpy->value))->toBe(50000);
});

it('reads a yen bank amount and a yen PayPal gross at the yen scale', function (): void {
    expect((new BankAmountParser)->parseMinor('1000', Currency::Jpy->value))->toBe(1000)
        ->and((new BankAmountParser)->parseMt940Minor('1000', Currency::Jpy->value))->toBe(1000)
        ->and((new PaypalAmountParser)->parseMinor('1000', Currency::Jpy->value))->toBe(1000);
});

it('keeps reading a euro amount in cents', function (): void {
    expect((new GenericCsvAmountParser)->parseMinor('-10.00', '.', Currency::Eur->value))->toBe(-1000)
        ->and((new BankAmountParser)->parseMinor('10.00', Currency::Eur->value))->toBe(1000)
        ->and((new BankAmountParser)->parseMt940Minor('10,00', Currency::Eur->value))->toBe(1000)
        ->and((new PaypalAmountParser)->parseMinor('10,00', Currency::Eur->value))->toBe(1000);
});

// A three-decimal currency is the same defect pointing the other way.
it('reads a dinar amount at its own three-decimal scale', function (): void {
    expect((new GenericCsvAmountParser)->parseMinor('1.234', '.', 'BHD'))->toBe(1234)
        ->and((new BankAmountParser)->parseMinor('1.234', 'BHD'))->toBe(1234);
});

// End to end through the adapter, whose Currency column is the only thing that
// says JPY: nothing upstream of it rejects the code.
it('parses a yen Revolut export at the figure the file carries', function (): void {
    $registry = new CsvPresetRegistry;
    $preset = $registry->get(CsvPresetRegistry::REVOLUT);
    expect($preset)->not->toBeNull();

    $adapter = new GenericCsvAdapter($preset, new GenericCsvAmountParser, new HeaderSniffer);

    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array(
        $adapter->parse(__DIR__.'/../fixtures/csv/revolut-jpy-sample.csv', $this->resolver),
        preserve_keys: false,
    );

    expect($dtos)->toHaveCount(2);
    expect($dtos[0]->currency)->toBe(Currency::Jpy->value);
    expect($dtos[0]->amountMinor)->toBe(-1000);
    expect($dtos[1]->amountMinor)->toBe(50000);
});

it('parses a yen MT940 statement at the figure the file carries', function (): void {
    $body = ":20:STMT-JPY\n:25:JP00BANK0000000001\n:28C:1/1\n:60F:C260401JPY100000,\n"
        .":61:2604010401D1000,NTRFKONBINI\n:86:100?20EREF+KONBINI?32KONBINI\n"
        .":62F:C260430JPY99000,\n-\n";

    /** @var Mt940Adapter $adapter */
    $adapter = $this->app->make(Mt940Adapter::class);

    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($adapter->parse(writeMt940Temp($body), $this->resolver), preserve_keys: false);

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->currency)->toBe(Currency::Jpy->value);
    expect($dtos[0]->amountMinor)->toBe(-1000);

    $meta = $adapter->statementMetadata();
    expect($meta)->not->toBeNull();
    expect($meta->openingBalanceMinor)->toBe(100000);
    expect($meta->closingBalanceMinor)->toBe(99000);
});
