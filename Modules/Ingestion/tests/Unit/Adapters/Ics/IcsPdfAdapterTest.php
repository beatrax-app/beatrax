<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Ics\IcsAmountParser;
use Modules\Ingestion\Internal\Adapters\Ics\IcsDateParser;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfHeaderProfile;
use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Exceptions\SniffMismatchException;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Ledger\Public\Dto\StatementSummaryData;

/*
 * Coverage for the ICS PDF adapter built atop the empirical Mijn ICS
 * consumer-portal monthly-statement fixture. The unit tests use a test-
 * double `PdfTextExtractor` that returns the committed `ics-sample-1.txt`
 * verbatim, so the adapter is fully exercised against the real text
 * layout without ever shelling out to pdftotext.
 *
 * HeaderSniffer is bypassed via a no-op double — the redacted .txt file
 * does not begin with `%PDF-` so the production sniff would (correctly)
 * reject it. The integration smoke test exercises the real sniff path
 * against the tiny synthetic .pdf instead.
 */

beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        /** @var list<string> */
        public array $askedFor = [];

        public function resolve(string $iban): AccountResolution
        {
            $this->askedFor[] = $iban;

            return AccountResolution::unknown($iban);
        }
    };

    $this->fixtureTxt = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt');
    $this->tinyPdf = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');

    $this->extractorDouble = new class($this->fixtureTxt) extends PdfTextExtractor
    {
        public function __construct(private readonly string $fixtureTxt)
        {
            parent::__construct();
        }

        public function extract(string $pdfPath): string
        {
            $contents = file_get_contents($this->fixtureTxt);
            if ($contents === false) {
                throw new RuntimeException('Could not read ICS text fixture.');
            }

            return $contents;
        }
    };

    $this->adapter = new IcsPdfAdapter(
        new HeaderSniffer,
        $this->extractorDouble,
        new IcsAmountParser,
        new IcsDateParser,
    );
});

it('reports its stable format identifier as ics-pdf', function (): void {
    expect($this->adapter->format())->toBe('ics-pdf');
})->group('phase-3');

it('parses the redacted .txt fixture into the expected SourceTransactionDto stream', function (): void {
    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($this->adapter->parse($this->tinyPdf, $this->resolver), false);

    // Empirical fixture: 22 transactions on page 1 + 16 on page 2 = 38
    // logical rows (each Wisselkoers FX line is folded into its merchant
    // row so a foreign-currency charge yields one canonical DTO).
    expect($dtos)->toHaveCount(38);

    foreach ($dtos as $dto) {
        expect($dto)->toBeInstanceOf(SourceTransactionDto::class);
    }

    // Snapshot the canonical DTO stream so any future regression on the
    // line-anchor parser or the FX-row folding is caught loudly. Each
    // entry is serialised to a stable shape before snapshot comparison.
    $serialised = array_map(
        static fn (SourceTransactionDto $dto): array => [
            'sourceRowIndex' => $dto->sourceRowIndex,
            'bookedAt' => $dto->bookedAt->toDateString(),
            'postedAt' => $dto->postedAt->toDateString(),
            'currency' => $dto->currency,
            'amountMinor' => $dto->amountMinor,
            'settledAmountMinor' => $dto->settledAmountMinor,
            'settledCurrency' => $dto->settledCurrency,
            'counterpartyName' => $dto->counterpartyName,
            'description' => $dto->description,
        ],
        $dtos,
    );

    expect($serialised)->toMatchSnapshot();
})->group('phase-3');

it('preserves the per-transaction extracted-text block in rawPayload under the ics-pdf format discriminator', function (): void {
    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($this->adapter->parse($this->tinyPdf, $this->resolver), false);

    foreach ($dtos as $dto) {
        expect($dto->rawPayload)->toHaveKey('format');
        expect($dto->rawPayload['format'])->toBe('ics-pdf');
        expect($dto->rawPayload)->toHaveKey('extractedText');
        expect($dto->rawPayload['extractedText'])->toBeString();
        expect($dto->rawPayload['extractedText'])->not->toBe('');
    }
})->group('phase-3');

it('emits monotonically increasing sourceRowIndex starting at zero', function (): void {
    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($this->adapter->parse($this->tinyPdf, $this->resolver), false);

    foreach ($dtos as $i => $dto) {
        expect($dto->sourceRowIndex)->toBe($i);
    }
})->group('phase-3');

it('rejects a file that fails the magic-byte sniff before reading any transaction', function (): void {
    // The redacted-text fixture (`.txt`) does NOT begin with %PDF- and
    // does not have a `.pdf` extension; the production sniffer rejects
    // it as not-a-PDF.
    expect(function (): void {
        iterator_to_array(
            $this->adapter->parse($this->fixtureTxt, $this->resolver),
            false,
        );
    })->toThrow(SniffMismatchException::class);
})->group('phase-3');

it('yields native + settled pair for a foreign-currency row', function (): void {
    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($this->adapter->parse($this->tinyPdf, $this->resolver), false);

    // Find the AUGMENT CODE USD row.
    $augment = null;
    foreach ($dtos as $dto) {
        if ($dto->counterpartyName !== null && str_contains($dto->counterpartyName, 'AUGMENT CODE')) {
            $augment = $dto;
            break;
        }
    }

    expect($augment)->not->toBeNull();
    /** @var SourceTransactionDto $augment */
    expect($augment->currency)->toBe('USD');
    expect($augment->amountMinor)->toBe(-5000);
    expect($augment->settledAmountMinor)->toBe(-4371);
    expect($augment->settledCurrency)->toBe('EUR');
    expect($augment->fxRateUsed)->toBeNull();
})->group('phase-3');

it('yields settledAmountMinor = null for an EUR-native row', function (): void {
    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($this->adapter->parse($this->tinyPdf, $this->resolver), false);

    // The first row (`IDEAL BETALING, DANK U`) is EUR-native and credited (`Bij`).
    $first = $dtos[0];
    expect($first->currency)->toBe('EUR');
    expect($first->settledAmountMinor)->toBeNull();
    expect($first->settledCurrency)->toBeNull();
    expect($first->fxRateUsed)->toBeNull();
})->group('phase-3');

it('discards card-number text from the per-transaction block before writing rawPayload', function (): void {
    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($this->adapter->parse($this->tinyPdf, $this->resolver), false);

    foreach ($dtos as $dto) {
        $block = $dto->rawPayload['extractedText'] ?? '';
        expect($block)->toBeString();
        /** @var string $block */
        // No canonical masked-card placeholder, no 12+ digit run.
        expect((bool) preg_match('/\*{4}-\*{4}-\*{4}-/u', $block))->toBeFalse();
        expect((bool) preg_match('/\d{12,}/', $block))->toBeFalse();
    }
})->group('phase-3');

it('exposes statement-summary tokens via statementMetadata() after parse() completes', function (): void {
    iterator_to_array($this->adapter->parse($this->tinyPdf, $this->resolver), false);

    $metadata = $this->adapter->statementMetadata();

    expect($metadata)->toBeInstanceOf(StatementSummaryData::class);
    /** @var StatementSummaryData $metadata */
    expect($metadata->openingBalanceMinor)->not->toBeNull();
    expect($metadata->closingBalanceMinor)->not->toBeNull();
    expect($metadata->extras)->not->toBeNull();
    /** @var array<string, mixed> $extras */
    $extras = $metadata->extras;
    expect($extras)->toHaveKey('issuer');
    expect($extras['issuer'])->toBe('Mastercard');
    expect($extras)->toHaveKey('cardholderName');
    expect($extras['cardholderName'])->toBe('STRIPPED');
    expect($extras)->toHaveKey('cardLast4');
    // Empirical fixture's last-four is redacted to `XXXX`.
    expect($extras['cardLast4'])->toBe('XXXX');
})->group('phase-3');

it('parses the six empirical summary amounts column-by-column from the four-token header + the two-column limit block', function (): void {
    iterator_to_array($this->adapter->parse($this->tinyPdf, $this->resolver), false);

    $metadata = $this->adapter->statementMetadata();

    expect($metadata)->toBeInstanceOf(StatementSummaryData::class);
    /** @var StatementSummaryData $metadata */
    // Opening balance and closing balance are stored as signed-negative
    // minor units (debits = owed to ICS). The four-column header row on
    // page 1 carries `€ 606,96  Af  € 606,96  Bij  € 1.416,50  Af
    // € 1.416,50  Af` — opening 606.96, received 606.96, charges
    // 1416.50, closing 1416.50.
    expect($metadata->openingBalanceMinor)->toBe(-60696);
    expect($metadata->closingBalanceMinor)->toBe(-141650);

    /** @var array<string, mixed> $extras */
    $extras = $metadata->extras;
    expect($extras['totalReceivedMinor'])->toBe(60696);
    expect($extras['totalChargesMinor'])->toBe(-141650);
    expect($extras['creditLimitMinor'])->toBe(250000);
    expect($extras['minimumDueMinor'])->toBe(141650);
})->group('phase-3');

it('reads the statement sequence number from the Volgnummer column', function (): void {
    iterator_to_array($this->adapter->parse($this->tinyPdf, $this->resolver), false);

    $metadata = $this->adapter->statementMetadata();

    expect($metadata)->toBeInstanceOf(StatementSummaryData::class);
    /** @var StatementSummaryData $metadata */
    expect($metadata->statementNumber)->toBe('2');
})->group('phase-3');

it('registers under the ics-pdf key in the SourceAdapterRegistry', function (): void {
    $registry = app(SourceAdapterRegistry::class);

    $adapter = $registry->for(IcsPdfHeaderProfile::FORMAT);

    expect($adapter)->toBeInstanceOf(IcsPdfAdapter::class);
    expect($registry->supportedFormats())->toContain('ics-pdf');
})->group('phase-3');
