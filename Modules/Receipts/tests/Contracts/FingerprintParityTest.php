<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvAdapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\KnownAccount;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Receipts\Internal\Matchers\IcsReceiptMatcher;
use Modules\Receipts\Internal\Matchers\PaypalReceiptMatcher;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;
use Modules\Receipts\Public\Pipeline\ReceiptSourceAdapter;

// A CSV row and its .eml receipt have to hash to the same fingerprint: the
// ENRICHED disposition in FingerprintStage::classify is what makes cross-format
// dedup work, and without parity every receipt import silently duplicates the
// CSV rows it matches.
dataset('fingerprintParityPairs', [
    'paypal' => [
        'emlPath' => __DIR__.'/../fixtures/paypal/current-receipt.eml',
        'csvPath' => __DIR__.'/../fixtures/paypal/paired-csv-row.csv',
        'matcherKey' => 'paypal-receipt',
        'wave' => 1,
    ],
    'ics' => [
        'emlPath' => __DIR__.'/../fixtures/ics/current-receipt.eml',
        // ICS has no CSV ingestion path, so the tiny PDF is the only twin
        // source. The path is lexical because datasets are built before the
        // app boots, where base_path() does not exist yet.
        'csvPath' => __DIR__.'/../../../Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf',
        'matcherKey' => 'ics-receipt',
        'wave' => 2,
    ],
    'google-play' => [
        'emlPath' => __DIR__.'/../fixtures/googleplay/wave2-fingerprint-pair.eml',
        'csvPath' => __DIR__.'/../fixtures/googleplay/wave2-fingerprint-pair.csv',
        'matcherKey' => 'google-play-receipt',
        'wave' => 2,
    ],
]);

// The fingerprint tuple includes accountId, so both sides only have to agree on
// the id for the two hashes to collapse onto each other.
final class FixedPaypalAccountResolver implements AccountResolver
{
    public function __construct(private readonly int $accountId) {}

    public function resolve(string $iban): KnownAccount
    {
        return new KnownAccount($this->accountId);
    }
}

// IcsPdfAdapter resolves the own-IBAN once before it starts iterating and does
// nothing with the answer, so a non-throwing implementation is all this arm needs.
final class FixedIcsAccountResolver implements AccountResolver
{
    public function __construct(private readonly int $accountId) {}

    public function resolve(string $iban): AccountResolution
    {
        return AccountResolution::known($this->accountId);
    }
}

it('produces equivalent fingerprints from receipt and CSV for the same logical transaction', function (
    string $emlPath,
    string $csvPath,
    string $matcherKey,
    int $wave,
): void {
    if (! file_exists($emlPath) || ! file_exists($csvPath)) {
        $this->markTestSkipped(
            "Fingerprint-parity fixture for matcher '{$matcherKey}' is missing. "
            ."Wave {$wave} must land: ".basename($emlPath).' + '.basename($csvPath)
            .'. Test scaffold ships in Wave 0; the gate activates the moment '
            .'both fixtures exist.'
        );
    }

    if ($matcherKey === 'google-play-receipt') {
        $this->markTestSkipped(
            "Matcher '{$matcherKey}' has no twin ingestion path in v1 — "
            .'parity covered by GooglePlayReceiptMatcherTest + the shared '
            .'NormalizeStage exercised by paypal + ics arms.'
        );
    }

    $seeded = $this->seedFixtureUserAndAccount();
    /** @var User $user */
    $user = $seeded['user'];
    $importRunId = 1;
    $normalize = $this->app->make(NormalizeStage::class);
    $composer = $this->app->make(FingerprintComposer::class);

    if ($matcherKey === 'paypal-receipt') {
        $accountId = $seeded['paypalAccount']->id;
        $accounts = new FixedPaypalAccountResolver($accountId);

        $matcher = new PaypalReceiptMatcher(new EmlMimeReader, app(BaseCurrency::class));
        $rawEml = (string) file_get_contents($emlPath);
        $matchOutcome = $matcher->match($rawEml);
        expect($matchOutcome->kind)->toBe('parsed');
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

    $matcher = new IcsReceiptMatcher(new EmlMimeReader);
    $rawEml = (string) file_get_contents($emlPath);
    $matchOutcome = $matcher->match($rawEml);
    expect($matchOutcome->kind)->toBe('parsed');
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
