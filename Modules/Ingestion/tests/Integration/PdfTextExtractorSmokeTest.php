<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Ics\IcsAmountParser;
use Modules\Ingestion\Internal\Adapters\Ics\IcsDateParser;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter;
use Modules\Ingestion\Internal\Adapters\Ics\IcsStatementHeader;
use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Services\HeaderSniffer;

// These exec the real `pdftotext`, hence the `integration` group: a CI host
// without poppler runs `pest --exclude-group=integration`.

it('extracts text from the tiny synthetic PDF via the real pdftotext binary', function (): void {
    $tinyPdf = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');

    $extractor = new PdfTextExtractor;
    $text = $extractor->extract($tinyPdf);

    expect($text)->toBeString();
    expect($text)->toContain('SYNTHETIC');
    expect($text)->toContain('KAARTHOUDER');
})->group('phase-3')->group('integration');

it('preserves -layout column structure on the synthetic PDF', function (): void {
    $tinyPdf = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');

    $extractor = new PdfTextExtractor;
    $text = $extractor->extract($tinyPdf);

    // `-raw` would collapse the whitespace padding between the merchant token
    // and the trailing direction marker; `-layout` keeps it as spaces.
    expect(str_contains($text, 'SYNTHETIC ICS TINY'))->toBeTrue();
    expect(str_contains($text, 'Af'))->toBeTrue();
})->group('phase-3')->group('integration');

it('reads the same statement into the same rows with pdftotext and with the in-app reader', function (): void {
    // The desktop's accuracy is the contract the device path has to meet. Two
    // readers, one statement, one row set — anything the fallback rounds off
    // shows up here as a difference rather than as a quietly missing purchase.
    $statement = base_path('Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf');

    $accounts = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return new AccountResolution(accountId: null, iban: $iban);
        }
    };

    $rowsFor = static function (PdfTextExtractor $extractor) use ($statement, $accounts): array {
        $adapter = new IcsPdfAdapter(new HeaderSniffer, $extractor, new IcsAmountParser, new IcsStatementHeader(new IcsDateParser));
        $rows = [];
        foreach ($adapter->parse($statement, $accounts) as $dto) {
            $rows[] = [
                $dto->bookedAt->toDateString(),
                $dto->postedAt->toDateString(),
                $dto->counterpartyName,
                $dto->currency,
                $dto->amountMinor,
                $dto->settledCurrency,
                $dto->settledAmountMinor,
            ];
        }

        return $rows;
    };

    $viaPoppler = $rowsFor(new PdfTextExtractor);
    $viaTextLayer = $rowsFor(new PdfTextExtractor('/usr/bin/this-binary-does-not-exist'));

    expect($viaPoppler)->toHaveCount(23);
    expect($viaTextLayer)->toBe($viaPoppler);
})->group('phase-3')->group('integration');
