<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvLanguageProfile;
use Modules\Ingestion\Internal\Exceptions\UnsupportedPaypalCsvShapeException;
use Modules\Ingestion\Public\Services\HeaderSniffer;

// PayPal's Saldorapport and Rapport Transactiegegevens exports are both
// comma-delimited `.csv` with the same byte-order mark. Without an explicit
// shape check the sniffer fell through to the language check and blamed the
// language — for a file that was Dutch and simply the wrong export.

beforeEach(function (): void {
    $this->sniffer = $this->app->make(HeaderSniffer::class);
});

it('rejects a PayPal Saldorapport CSV with an actionable shape error', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'sniff-pp-brr-').'.csv';
    // Verbatim first lines of a real Saldorapport export. The `RH` record-type
    // token is the discriminator: the Transactiegegevens export opens on the
    // column-name row instead.
    file_put_contents(
        $tmp,
        '"RH","Naam rapport","Status rapport","Begindatum en -tijd van rapport","Einddatum en -tijd van rapport","Datum en tijd voor het genereren van rapporten","Hiërarchie","Tijdzone"'."\n"
        .'"RH","BALANCE_RECONCILIATION_REPORT","Success","2026/04/01 00:00:00 +0200","2026/05/12 14:00:00 +0200","2026/05/12 18:17:08 +0200","ABC123","Europe/Berlin"'."\n"
    );

    try {
        expect(fn () => $this->sniffer->sniff($tmp, PaypalCsvLanguageProfile::FORMAT))
            ->toThrow(UnsupportedPaypalCsvShapeException::class, 'Rapport Transactiegegevens');
    } finally {
        @unlink($tmp);
    }
})->group('phase-16.1.1');

it('still accepts the Rapport Transactiegegevens CSV shape after the Saldorapport guard is in place', function (): void {
    $result = $this->sniffer->sniff(
        base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv'),
        PaypalCsvLanguageProfile::FORMAT,
    );

    expect($result->format)->toBe(PaypalCsvLanguageProfile::FORMAT);
})->group('phase-16.1.1');
