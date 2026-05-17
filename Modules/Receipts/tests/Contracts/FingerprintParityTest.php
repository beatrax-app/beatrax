<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvAdapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\KnownAccount;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Public\Services\FingerprintComposer;
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
 * The 'paypal' dataset row carries the active assertion path: load
 * the paired CSV row through PaypalCsvAdapter, load the paired .eml
 * through PaypalReceiptMatcher + ReceiptSourceAdapter, push both
 * through NormalizeStage, and assert FingerprintComposer.compose
 * returns identical SHA-256 hashes.
 *
 * The 'ics' and 'google-play' rows still skip with a wave-pointer
 * message until Wave 2 lands their matcher + fixtures.
 */
dataset('fingerprintParityPairs', [
    'paypal' => [
        'emlPath' => __DIR__.'/../fixtures/paypal/current-receipt.eml',
        'csvPath' => __DIR__.'/../fixtures/paypal/paired-csv-row.csv',
        'matcherKey' => 'paypal-receipt',
        'wave' => 1,
    ],
    'ics' => [
        'emlPath' => __DIR__.'/../fixtures/ics/wave2-fingerprint-pair.eml',
        'csvPath' => __DIR__.'/../fixtures/ics/wave2-fingerprint-pair.csv',
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

    if ($matcherKey !== 'paypal-receipt') {
        // Wave 2 lands the ICS + Google Play active assertion path
        // alongside the matcher class + fixture pair.
        $this->markTestSkipped(
            "Matcher '{$matcherKey}' arm lands in Wave {$wave}; only the paypal "
            .'arm is active in Wave 1.'
        );
    }

    $seeded = $this->seedFixtureUserAndAccount();
    /** @var User $user */
    $user = $seeded['user'];
    $accountId = $seeded['paypalAccount']->id;

    $importRunId = 1;
    $accounts = new FixedPaypalAccountResolver($accountId);
    $normalize = $this->app->make(NormalizeStage::class);

    // Receipt-side: matcher → adapter → SourceTransactionDto → normalize.
    $matcher = new PaypalReceiptMatcher(new EmlMimeReader);
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

    // Sanity: the canonical comparators that feed FingerprintComposer
    // must align before the hash check. Comparing them explicitly here
    // gives a clearer failure message than a bare hash inequality.
    expect($csvCanonical->bookedAt->toDateTimeString())->toBe($receiptCanonical->bookedAt->toDateTimeString());
    expect($csvCanonical->postedAt->toDateString())->toBe($receiptCanonical->postedAt->toDateString());
    expect($csvCanonical->amountMinor)->toBe($receiptCanonical->amountMinor);
    expect($csvCanonical->currency)->toBe($receiptCanonical->currency);
    expect($csvCanonical->counterpartyNormalized)->toBe($receiptCanonical->counterpartyNormalized);

    $composer = $this->app->make(FingerprintComposer::class);
    expect($composer->compose($receiptCanonical))->toBe($composer->compose($csvCanonical));
})->with('fingerprintParityPairs');
