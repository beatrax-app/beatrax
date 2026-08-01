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

/**
 * LOAD-BEARING contract: receipts and CSVs MUST produce
 * fingerprint-equivalent transactions.
 *
 * The cross-format dedup mechanism inherited from Phase 2 (the
 * ENRICHED disposition in FingerprintStage::classify) requires that
 * a PayPal CSV row and its corresponding PayPal `.eml` receipt hash
 * to the same fingerprint. If they don't, every receipt import
 * silently duplicates the matching CSV rows — the cross-format dedup
 * pay-off breaks.
 *
 * The 'paypal' dataset row pairs the PayPal Activity Download CSV
 * with the matching PayPal `.eml` receipt.
 *
 * The 'ics' dataset row pairs the Mijn ICS tiny consumer-portal PDF
 * with a matching ICS `.eml` receipt. ICS has no CSV ingestion path
 * (the consumer portal is PDF-only), so the PDF row is the only
 * available twin source. The receipt fixture is intentionally aligned
 * with the single row the tiny PDF contains (`SYNTHETIC ICS TINY`,
 * EUR 1,00, 12 apr 2026) so both paths converge bit-for-bit at
 * FingerprintComposer.compose.
 *
 * The 'google-play' row still skips with a wave-pointer message —
 * Google Play has no CSV/PDF twin in v1 (no second ingestion path
 * exists), so a same-row fingerprint parity assertion is not
 * meaningful. The matcher's output flows through the same
 * NormalizeStage as every other receipt so behaviour is exercised
 * via the matcher unit tests instead.
 */
dataset('fingerprintParityPairs', [
    'paypal' => [
        'emlPath' => __DIR__.'/../fixtures/paypal/current-receipt.eml',
        'csvPath' => __DIR__.'/../fixtures/paypal/paired-csv-row.csv',
        'matcherKey' => 'paypal-receipt',
        'wave' => 1,
    ],
    'ics' => [
        'emlPath' => __DIR__.'/../fixtures/ics/current-receipt.eml',
        // Reuses the Phase 3 tiny ICS PDF fixture as the twin source.
        // ICS has no CSV ingestion path — PDF is the only sibling.
        // Compute the path lexically from the test file location so it
        // resolves at dataset-build time (before the app has booted —
        // base_path() is unavailable here).
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

/**
 * Minimal in-test AccountResolver: returns a fixed KnownAccount for
 * any IBAN. The fingerprint tuple uses accountId so as long as both
 * sides receive the same id the hash collapses.
 */
final class FixedPaypalAccountResolver implements AccountResolver
{
    public function __construct(private readonly int $accountId) {}

    public function resolve(string $iban): KnownAccount
    {
        return new KnownAccount($this->accountId);
    }
}

/**
 * AccountResolver double for the ICS-PDF arm of the parity test. The
 * IcsPdfAdapter calls `$accounts->resolve($ownIban)` (fire-and-forget)
 * once before the row iteration begins. The production
 * EloquentAccountResolver returns an `AccountResolution` sum-type; the
 * test only needs a non-throwing implementation that satisfies the
 * `AccountResolver` interface contract.
 */
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
        // Google Play has no second ingestion path in v1 — no CSV/PDF
        // twin exists, so a same-row fingerprint parity assertion is
        // not meaningful. Matcher behaviour is covered by its unit
        // tests; the downstream NormalizeStage path is exercised by
        // every other receipt arm.
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

        // Receipt-side: matcher → adapter → SourceTransactionDto → normalize.
        $matcher = new PaypalReceiptMatcher(new EmlMimeReader, app(BaseCurrency::class));
        $rawEml = (string) file_get_contents($emlPath);
        $matchOutcome = $matcher->match($rawEml);
        expect($matchOutcome->kind)->toBe('parsed');
        expect($matchOutcome->parsed)->not->toBeNull();

        $receiptSource = (new ReceiptSourceAdapter)->toSourceDto($matchOutcome->parsed, sourceRowIndex: 0);
        $receiptCanonical = $normalize->run($receiptSource, $accountId, $user, $importRunId, 'eml');

        // CSV-side: adapter parse → first SourceTransactionDto → normalize.
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

    // ICS arm — receipt vs ICS PDF (the only available twin source
    // since ICS has no CSV ingestion path). The fixture pair is
    // deliberately aligned: the receipt's merchant + amount + booked
    // date map to the SYNTHETIC ICS TINY row the tiny PDF contains.
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
