<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvAdapter;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Receipts\Internal\MatcherRegistry;
use Modules\Receipts\Internal\Matchers\IcsReceiptMatcher;
use Modules\Receipts\Internal\Matchers\PaypalReceiptMatcher;
use Modules\Receipts\Internal\Matchers\ReceiptBodyText;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;
use Modules\Receipts\Public\Pipeline\ReceiptSourceAdapter;
use Modules\Receipts\Tests\Support\FixedIcsAccountResolver;
use Modules\Receipts\Tests\Support\FixedPaypalAccountResolver;

// A CSV row and its .eml receipt have to hash to the same fingerprint: the
// ENRICHED disposition in FingerprintStage::classify is what makes cross-format
// dedup work, and without parity every receipt import silently duplicates the
// CSV rows it matches. Only a matcher with a twin ingestion format can be
// paired; the arm that had none is asserted below rather than declared here and
// skipped, which is how a third of this contract came to never execute.
dataset('fingerprintParityPairs', [
    'paypal' => [
        'emlPath' => __DIR__.'/../fixtures/paypal/current-receipt.eml',
        'csvPath' => __DIR__.'/../fixtures/paypal/paired-csv-row.csv',
        'matcherKey' => 'paypal-receipt',
    ],
    'ics' => [
        'emlPath' => __DIR__.'/../fixtures/ics/current-receipt.eml',
        // ICS has no CSV ingestion path, so the tiny PDF is the only twin
        // source. The path is lexical because datasets are built before the
        // app boots, where base_path() does not exist yet.
        'csvPath' => __DIR__.'/../../../Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf',
        'matcherKey' => 'ics-receipt',
    ],
]);

// A declared pair whose fixtures are absent is a contract that never runs, so
// the absence fails here rather than skipping quietly.
it('ships both fixtures for every declared parity pair', function (string $emlPath, string $csvPath, string $matcherKey): void {
    expect(file_exists($emlPath))->toBeTrue("Missing receipt fixture for '{$matcherKey}': ".basename($emlPath));
    expect(file_exists($csvPath))->toBeTrue("Missing twin-source fixture for '{$matcherKey}': ".basename($csvPath));
})->with('fingerprintParityPairs');

// Google Play issues receipts and no statement export, so there is no second
// format for its rows to be deduplicated against and no pair to declare. Stated
// as an assertion, because the arm that used to claim one skipped itself twice
// over and read as covered.
it('declares a parity pair for exactly the matchers that have a twin ingestion format', function (): void {
    /** @var MatcherRegistry $registry */
    $registry = $this->app->make(MatcherRegistry::class);

    expect($registry->supportedKeys())->toContain('google-play-receipt');
    expect(array_map(
        static fn (SourceFormat $format): string => $format->value,
        SourceFormat::cases(),
    ))->not->toContain('google-play-csv', 'google-play-pdf');
});

it('produces equivalent fingerprints from receipt and CSV for the same logical transaction', function (
    string $emlPath,
    string $csvPath,
    string $matcherKey,
): void {
    $seeded = $this->seedFixtureUserAndAccount();
    /** @var User $user */
    $user = $seeded['user'];
    $importRunId = 1;
    $normalize = $this->app->make(NormalizeStage::class);
    $composer = $this->app->make(FingerprintComposer::class);

    if ($matcherKey === 'paypal-receipt') {
        $accountId = $seeded['paypalAccount']->id;
        $accounts = new FixedPaypalAccountResolver($accountId);

        $matcher = new PaypalReceiptMatcher(new EmlMimeReader, app(BaseCurrency::class), new ReceiptBodyText);
        $rawEml = (string) file_get_contents($emlPath);
        $matchOutcome = $matcher->match($rawEml);
        expect($matchOutcome->kind)->toBe(MatchOutcomeKind::Parsed);
        expect($matchOutcome->parsed)->not->toBeNull();

        $receiptSource = (new ReceiptSourceAdapter)->toSourceDto($matchOutcome->parsed, sourceRowIndex: 0);
        $receiptCanonical = $normalize->run($receiptSource, $accountId, $user, $importRunId, 'eml');

        /** @var PaypalCsvAdapter $csvAdapter */
        $csvAdapter = $this->app->make(PaypalCsvAdapter::class);
        /** @var SourceTransactionDto|null $csvSource */
        $csvSource = null;
        foreach ($csvAdapter->parse($csvPath, $accounts) as $row) {
            $csvSource = $row;
            break;
        }
        expect($csvSource)->not->toBeNull();

        /** @var SourceTransactionDto $csvSource */
        $csvCanonical = $normalize->run($csvSource, $accountId, $user, $importRunId, 'paypal-csv');

        expect($csvCanonical->bookedAt->toDateTimeString())->toBe($receiptCanonical->bookedAt->toDateTimeString());
        expect($csvCanonical->postedAt->toDateString())->toBe($receiptCanonical->postedAt->toDateString());
        expect($csvCanonical->amountMinor)->toBe($receiptCanonical->amountMinor);
        expect($csvCanonical->currency)->toBe($receiptCanonical->currency);
        expect($csvCanonical->counterpartyNormalized)->toBe($receiptCanonical->counterpartyNormalized);

        expect($composer->compose($receiptCanonical))->toBe($composer->compose($csvCanonical));

        return;
    }

    // The ICS fixtures are deliberately aligned: the receipt's merchant, amount
    // and booked date map onto the single row the tiny PDF contains, which is
    // what lets the two paths converge bit-for-bit.
    $accountId = $seeded['icsAccount']->id;
    $accounts = new FixedIcsAccountResolver($accountId);

    $matcher = new IcsReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);
    $rawEml = (string) file_get_contents($emlPath);
    $matchOutcome = $matcher->match($rawEml);
    expect($matchOutcome->kind)->toBe(MatchOutcomeKind::Parsed);
    expect($matchOutcome->parsed)->not->toBeNull();

    $receiptSource = (new ReceiptSourceAdapter)->toSourceDto($matchOutcome->parsed, sourceRowIndex: 0);
    $receiptCanonical = $normalize->run($receiptSource, $accountId, $user, $importRunId, 'eml');

    /** @var IcsPdfAdapter $pdfAdapter */
    $pdfAdapter = $this->app->make(IcsPdfAdapter::class);
    /** @var SourceTransactionDto|null $pdfSource */
    $pdfSource = null;
    foreach ($pdfAdapter->parse($csvPath, $accounts) as $row) {
        $pdfSource = $row;
        break;
    }
    expect($pdfSource)->not->toBeNull();

    /** @var SourceTransactionDto $pdfSource */
    $pdfCanonical = $normalize->run($pdfSource, $accountId, $user, $importRunId, 'ics-pdf');

    expect($pdfCanonical->bookedAt->toDateTimeString())->toBe($receiptCanonical->bookedAt->toDateTimeString());
    expect($pdfCanonical->postedAt->toDateString())->toBe($receiptCanonical->postedAt->toDateString());
    expect($pdfCanonical->amountMinor)->toBe($receiptCanonical->amountMinor);
    expect($pdfCanonical->currency)->toBe($receiptCanonical->currency);
    expect($pdfCanonical->counterpartyNormalized)->toBe($receiptCanonical->counterpartyNormalized);

    expect($composer->compose($receiptCanonical))->toBe($composer->compose($pdfCanonical));
})->with('fingerprintParityPairs');
