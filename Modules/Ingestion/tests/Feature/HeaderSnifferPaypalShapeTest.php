<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvLanguageProfile;
use Modules\Ingestion\Public\Exceptions\UnsupportedPaypalCsvShapeException;
use Modules\Ingestion\Public\Services\HeaderSniffer;

/*
 * Locks the user-facing error message the sniffer surfaces when the user
 * uploads a PayPal Balance Reconciliation Report (BRR) instead of the
 * Activity Download CSV. The two exports look superficially identical
 * (`.csv`, comma-delimited, UTF-8 with BOM) but the BRR ships rows
 * prefixed by record-type tokens (`RH`, `RD`, `RF`) and a completely
 * different column shape. Without an explicit shape check, the sniffer
 * fell through to the language-profile check and surfaced a misleading
 * "language profile not supported" message — the file was in Dutch but
 * the export type was wrong.
 */

beforeEach(function (): void {
    $this->sniffer = $this->app->make(HeaderSniffer::class);
});

it('rejects a PayPal Balance Reconciliation Report CSV with an actionable shape error', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'sniff-pp-brr-').'.csv';
    // Empirical first lines of the Balance Reconciliation Report export
    // from the PayPal portal. The `RH` (Report Header) row is the
    // discriminator — the Activity Download starts with the column-name
    // header row directly.
    file_put_contents(
        $tmp,
        '"RH","Naam rapport","Status rapport","Begindatum en -tijd van rapport","Einddatum en -tijd van rapport","Datum en tijd voor het genereren van rapporten","Hiërarchie","Tijdzone"'."\n"
        .'"RH","BALANCE_RECONCILIATION_REPORT","Success","2026/04/01 00:00:00 +0200","2026/05/12 14:00:00 +0200","2026/05/12 18:17:08 +0200","ABC123","Europe/Berlin"'."\n"
    );

    try {
        expect(fn () => $this->sniffer->sniff($tmp, PaypalCsvLanguageProfile::FORMAT))
            ->toThrow(UnsupportedPaypalCsvShapeException::class, 'Activity Download');
    } finally {
        @unlink($tmp);
    }
})->group('phase-16.1.1');

it('still accepts the Activity Download CSV shape after the BRR guard is in place', function (): void {
    // Regression backstop: the BRR guard must not false-positive on the
    // happy path. The redacted Activity Download fixture must still sniff
    // cleanly.
    $result = $this->sniffer->sniff(
        base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv'),
        PaypalCsvLanguageProfile::FORMAT,
    );

    expect($result->format)->toBe(PaypalCsvLanguageProfile::FORMAT);
})->group('phase-16.1.1');
