<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Ics\IcsAmountParser;
use Modules\Ingestion\Internal\Adapters\Ics\IcsDateParser;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter;
use Modules\Ingestion\Internal\Adapters\Ics\IcsStatementHeader;
use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\HeaderSniffer;

// An ICS row prints a month abbreviation and no year, so the year is inferred
// from the statement header. These cases are the turn of the year, where the
// inference is the only thing standing between a real date and one 12 months out.

beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    $this->tinyPdf = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');

    $this->parseStatement = function (string $statementDate, string $rows): array {
        $text = <<<TXT
             Datum                    ICS-klantnummer      Volgnummer      Bladnummer
             {$statementDate}         KLANTNUMMER          2               1 van 1
             Datum      Datum        Omschrijving          Bedrag in       Bedrag
             transactie boeking                            vreemde valuta  in euro's
            {$rows}
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

it('dates a December transaction booked in December to the year before a January statement rather than a year into the future', function (): void {
    $dtos = ($this->parseStatement)(
        '5 januari 2026',
        ' 20 dec.   22 dec.   ALBERT HEIJN 1234   UTRECHT   NL   25,00   Af',
    );

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->postedAt->toDateString())->toBe('2025-12-20');
    expect($dtos[0]->bookedAt->toDateString())->toBe('2025-12-22');
})->group('phase-3');

it('splits a December transaction booked in January across the year boundary on a January statement', function (): void {
    $dtos = ($this->parseStatement)(
        '5 januari 2026',
        ' 31 dec.   2 jan.   ALBERT HEIJN 1234   UTRECHT   NL   25,00   Af',
    );

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->postedAt->toDateString())->toBe('2025-12-31');
    expect($dtos[0]->bookedAt->toDateString())->toBe('2026-01-02');
})->group('phase-3');

it('keeps a December transaction on a December statement inside the statement year', function (): void {
    $dtos = ($this->parseStatement)(
        '28 december 2025',
        ' 20 dec.   22 dec.   ALBERT HEIJN 1234   UTRECHT   NL   25,00   Af',
    );

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->postedAt->toDateString())->toBe('2025-12-20');
    expect($dtos[0]->bookedAt->toDateString())->toBe('2025-12-22');
})->group('phase-3');

it('keeps a January transaction on a January statement inside the statement year', function (): void {
    $dtos = ($this->parseStatement)(
        '31 januari 2026',
        ' 3 jan.   4 jan.   ALBERT HEIJN 1234   UTRECHT   NL   25,00   Af',
    );

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->postedAt->toDateString())->toBe('2026-01-03');
    expect($dtos[0]->bookedAt->toDateString())->toBe('2026-01-04');
})->group('phase-3');

it('rolls a November row back a year on a February statement, not only the December ones', function (): void {
    $dtos = ($this->parseStatement)(
        '15 februari 2026',
        ' 28 nov.   29 nov.   ALBERT HEIJN 1234   UTRECHT   NL   25,00   Af',
    );

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->postedAt->toDateString())->toBe('2025-11-28');
    expect($dtos[0]->bookedAt->toDateString())->toBe('2025-11-29');
})->group('phase-3');
