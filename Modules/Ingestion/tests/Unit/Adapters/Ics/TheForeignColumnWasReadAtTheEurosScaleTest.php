<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Ics\IcsAmountParser;
use Modules\Ingestion\Internal\Adapters\Ics\IcsDateParser;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter;
use Modules\Ingestion\Internal\Adapters\Ics\IcsStatementHeader;
use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\HeaderSniffer;

// An ICS statement has two amount columns — "Bedrag in vreemde valuta" beside
// "Bedrag in euro's" — and ics-sample-1.txt prints 50,00 USD, 8,99 GBP and
// 6,00 USD in the first of them. The adapter reads that column as the row's
// own amount and currency, so the amount parser was the one boundary in this
// module deciding the fractional-digit count without asking the currency: a
// yen has none, and a JPY row was refused outright.
function foreignColumnStatementText(string $foreignAmount, string $foreignCurrency, string $rate): string
{
    return "International Card Services BV\n"
        ."Datum                ICS-klantnummer     Volgnummer     Bladnummer\n"
        ."15 februari 2026     KLANTNUMMER         2              1 van 1\n"
        ."Uw Card met als laatste vier cijfers 1234 (****-****-****-1234)\n"
        ."Datum      Datum      Omschrijving                Bedrag in         Bedrag\n"
        ."transactie boeking                                 vreemde valuta    in euro's\n"
        ."23 jan.    24 jan.    KYOTO SHOP    TOKYO    JP    {$foreignAmount} {$foreignCurrency}    7,42    Af\n"
        ."                      Wisselkoers {$foreignCurrency}    {$rate}\n";
}

beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    $this->tinyPdf = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');
    $this->parser = new IcsAmountParser;

    $this->adapterFor = function (string $text): IcsPdfAdapter {
        $extractor = new class($text) extends PdfTextExtractor
        {
            public function __construct(private readonly string $text)
            {
                parent::__construct();
            }

            public function extract(string $pdfPath): string
            {
                return $this->text;
            }
        };

        return new IcsPdfAdapter(new HeaderSniffer, $extractor, new IcsAmountParser, new IcsStatementHeader(new IcsDateParser));
    };
});

it('reads a yen row in the foreign column at the yen\'s own scale', function (): void {
    $adapter = ($this->adapterFor)(foreignColumnStatementText('1.234', 'JPY', '166,32'));

    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($adapter->parse($this->tinyPdf, $this->resolver), false);

    expect($dtos)->toHaveCount(1)
        ->and($dtos[0]->currency)->toBe('JPY')
        ->and($dtos[0]->amountMinor)->toBe(-1234)
        ->and($dtos[0]->settledAmountMinor)->toBe(-742)
        ->and($dtos[0]->settledCurrency)->toBe('EUR');
});

it('still reads the two-decimal foreign currencies the committed statement prints', function (string $raw, string $currency, int $expected): void {
    expect($this->parser->parse($raw, $currency))->toBe($expected);
})->with([
    'the USD row' => ['50,00', 'USD', 5000],
    'the GBP row' => ['8,99', 'GBP', 899],
    'the euro column' => ['1.416,50', 'EUR', 141650],
]);

// The scale cuts both ways. A shape the currency cannot hold has to stay a
// refusal: read as two decimals, "12,34" in yen is ¥1234 — a hundred times the
// figure — which is the same misreading the euro column's fixed rule prevents.
it('refuses a shape the named currency cannot hold', function (string $raw, ?string $currency): void {
    expect(fn (): int => $this->parser->parse($raw, $currency))
        ->toThrow(InvalidAmountException::class);
})->with([
    'two decimals in a currency with none' => ['12,34', 'JPY'],
    'one decimal in a currency with none' => ['12,3', 'JPY'],
    'a bare integer run with no currency named' => ['1.234', null],
    'a bare integer run in euros' => ['1.234', 'EUR'],
    'exchange-rate precision in euros' => ['1,14390', 'EUR'],
]);

it('keeps the sign and the trailing-minus form at a scale of one', function (): void {
    expect($this->parser->parse('-1.234', 'JPY'))->toBe(-1234)
        ->and($this->parser->parse('1.234-', 'JPY'))->toBe(-1234)
        ->and($this->parser->parse('¥ 1.234', 'JPY'))->toBe(1234);
});
