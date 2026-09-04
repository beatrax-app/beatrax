<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Ics\IcsAmountParser;
use Modules\Ingestion\Internal\Adapters\Ics\IcsDateParser;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter;
use Modules\Ingestion\Internal\Adapters\Ics\IcsStatementHeader;
use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;
use Modules\Ingestion\Internal\Exceptions\IcsStatementDateUnreadableException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\HeaderSniffer;

// An ICS row states a day and a month and no year at all, so the statement's
// own date is the only year the whole file has. Read from the wall clock
// instead, a statement out of the archive imports as if it were this year's.

beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    $this->tinyPdf = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');

    $this->parseStatement = function (string $headerDateCell): array {
        $text = <<<TXT
             Datum                    ICS-klantnummer      Volgnummer      Bladnummer
             {$headerDateCell}        KLANTNUMMER          2               1 van 1
             Datum      Datum        Omschrijving          Bedrag in       Bedrag
             transactie boeking                            vreemde valuta  in euro's
             20 dec.   22 dec.   ALBERT HEIJN 1234   UTRECHT   NL   25,00   Af
            TXT;

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

        $adapter = new IcsPdfAdapter(
            new HeaderSniffer,
            $extractor,
            new IcsAmountParser,
            new IcsStatementHeader(new IcsDateParser),
        );

        /** @var list<SourceTransactionDto> $dtos */
        $dtos = iterator_to_array($adapter->parse($this->tinyPdf, $this->resolver), false);

        return $dtos;
    };
});

it('refuses a statement whose own date cannot be read instead of dating its rows from the clock', function (): void {
    ($this->parseStatement)('32 januari 2026');
})->throws(IcsStatementDateUnreadableException::class);

it('refuses a statement that states no date at all', function (): void {
    ($this->parseStatement)('KLANTNUMMER');
})->throws(IcsStatementDateUnreadableException::class);

it('reads a statement that does state a date, and dates the row from it', function (): void {
    $dtos = ($this->parseStatement)('5 januari 2026');

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->postedAt->toDateString())->toBe('2025-12-20');
    expect($dtos[0]->bookedAt->toDateString())->toBe('2025-12-22');
});

it('dates an archived statement from the year it states, not from the year it is imported in', function (): void {
    $dtos = ($this->parseStatement)('5 januari 2023');

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->postedAt->toDateString())->toBe('2022-12-20');
    expect($dtos[0]->bookedAt->toDateString())->toBe('2022-12-22');
});
