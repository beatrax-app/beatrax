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
use Modules\Ledger\Public\Dto\StatementSummaryData;

// Every ICS figure is printed positive with an Af/Bij marker beside it, and the
// adapter honoured that marker on the transaction rows while throwing it away
// on the totals of the same file: the four summary columns were signed by which
// column they were in instead. That is right for the one empirical statement
// committed here, which reads Af / Bij / Af / Af, and wrong for any card paid
// off past zero. The opening balance is what anchors
// accounts.starting_balance_minor, so a credit stored as the same amount owed
// puts every balance, net-worth point, forecast anchor and reconcile target for
// that card out by twice the credit.

beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    $this->tinyPdf = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');

    // The sniffer is the real one and only ever sees the tiny .pdf; the text
    // layer behind it is the synthesised statement that closes in credit.
    $extractor = new class(base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-credit.txt')) extends PdfTextExtractor
    {
        public function __construct(private readonly string $fixtureTxt)
        {
            parent::__construct();
        }

        public function extract(string $pdfPath): string
        {
            $contents = file_get_contents($this->fixtureTxt);
            if ($contents === false) {
                throw new RuntimeException('Could not read the ICS credit-balance text fixture.');
            }

            return $contents;
        }
    };

    $this->adapter = new IcsPdfAdapter(
        new HeaderSniffer,
        $extractor,
        new IcsAmountParser,
        new IcsStatementHeader(new IcsDateParser),
    );
});

it('stores a Bij balance as a credit rather than as the same amount owed', function (): void {
    iterator_to_array($this->adapter->parse($this->tinyPdf, $this->resolver), false);

    $metadata = $this->adapter->statementMetadata();

    expect($metadata)->toBeInstanceOf(StatementSummaryData::class);
    /** @var StatementSummaryData $metadata */
    // The fixture's summary reads "€ 93,04 Bij  € 0,00 Bij  € 43,71 Af
    // € 49,33 Bij": the card opens in credit and, after one purchase, closes in
    // credit. Both balances were stored negative while the marker was dropped.
    expect($metadata->openingBalanceMinor)->toBe(9304);
    expect($metadata->closingBalanceMinor)->toBe(4933);
    expect($metadata->openingBalanceCurrency)->toBe('EUR');
    expect($metadata->closingBalanceCurrency)->toBe('EUR');
})->group('phase-3');

it('signs the two informational total columns by their own markers too', function (): void {
    iterator_to_array($this->adapter->parse($this->tinyPdf, $this->resolver), false);

    $metadata = $this->adapter->statementMetadata();

    expect($metadata)->toBeInstanceOf(StatementSummaryData::class);
    /** @var StatementSummaryData $metadata */
    expect($metadata->extras)->not->toBeNull();

    /** @var array<string, mixed> $extras */
    $extras = $metadata->extras;

    expect($extras['totalReceivedMinor'])->toBe(0);
    expect($extras['totalChargesMinor'])->toBe(-4371);
})->group('phase-3');

// The arithmetic is the part that outlives this fixture: a statement closes on
// its opening balance plus what it received plus what it charged, whichever way
// round each of those was printed. Signed by position, that identity holds only
// while every position happens to be printed the way it was assumed to be.
it('closes on the figure its own three columns add up to', function (): void {
    iterator_to_array($this->adapter->parse($this->tinyPdf, $this->resolver), false);

    $metadata = $this->adapter->statementMetadata();

    expect($metadata)->toBeInstanceOf(StatementSummaryData::class);
    /** @var StatementSummaryData $metadata */
    expect($metadata->extras)->not->toBeNull();

    /** @var array<string, mixed> $extras */
    $extras = $metadata->extras;

    expect($metadata->openingBalanceMinor + $extras['totalReceivedMinor'] + $extras['totalChargesMinor'])
        ->toBe($metadata->closingBalanceMinor);
})->group('phase-3');

it('bills the one purchase the credit statement carries', function (): void {
    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($this->adapter->parse($this->tinyPdf, $this->resolver), false);

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->amountMinor)->toBe(-4371);
    expect($dtos[0]->currency)->toBe('EUR');
    expect($dtos[0]->postedAt->toDateString())->toBe('2026-02-20');
    expect($dtos[0]->bookedAt->toDateString())->toBe('2026-02-21');
})->group('phase-3');
