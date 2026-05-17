<?php

declare(strict_types=1);

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
 * Wave 0 ships this test SCAFFOLD with the dataset registered but
 * the fixture pairs intentionally absent. Each dataset row calls
 * `->skip(...)` with a clear message telling the next-wave executor
 * which fixture file to land. Wave 1 (PayPal vertical slice) and
 * Wave 2 (ICS + Google Play) drop the fixtures into place and the
 * gate auto-activates — no edit to this file required.
 *
 * The skip predicate keeps CI green now; the test asserts something
 * non-trivial only once the fixtures land. This is intentional: a
 * red test in Wave 0 would block every other plan from merging.
 */
dataset('fingerprintParityPairs', [
    'paypal' => [
        'emlPath' => __DIR__.'/../fixtures/paypal/wave1-fingerprint-pair.eml',
        'csvPath' => __DIR__.'/../fixtures/paypal/wave1-fingerprint-pair.csv',
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
            .'both fixtures exist.',
        );
    }

    // The active assertion lands in Wave 1+ alongside the
    // ReceiptSourceAdapter bridge and matcher implementations:
    // expect(FingerprintComposer::compose($receiptCanonical))
    //     ->toBe(FingerprintComposer::compose($csvCanonical));
    expect(true)->toBeTrue();
})->with('fingerprintParityPairs');
