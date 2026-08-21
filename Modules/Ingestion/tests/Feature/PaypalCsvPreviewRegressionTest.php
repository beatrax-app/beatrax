<?php

declare(strict_types=1);

use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Import\Public\Enums\PreviewRowStatus;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->importer = $this->app->make(RunsImports::class);
});

it('parses the redacted PayPal Rapport Transactiegegevens fixture and produces at least one non-error preview row', function (): void {
    $fixture = base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv');

    $result = $this->importer->runFromUpload(
        $fixture,
        'paypal-csv',
        $this->fixtureUser,
        'paypal-sample-1.csv',
    );

    expect(count($result->rows))->toBeGreaterThanOrEqual(1);
    expect(array_filter($result->rows, fn ($r) => $r->status === PreviewRowStatus::Error))->toBe([]);
})->group('phase-16.1.1');

it('names the report the reader actually needs when they upload a PayPal Saldorapport by mistake', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'paypal-brr-regression-').'.csv';
    file_put_contents(
        $tmp,
        '"RH","Naam rapport","Status rapport","Begindatum en -tijd van rapport","Einddatum en -tijd van rapport","Datum en tijd voor het genereren van rapporten","Hiërarchie","Tijdzone"'."\n"
        .'"RH","BALANCE_RECONCILIATION_REPORT","Success","2026/04/01 00:00:00 +0200","2026/05/12 14:00:00 +0200","2026/05/12 18:17:08 +0200","ABC123","Europe/Berlin"'."\n"
        .'"RD","col1","col2"'."\n"
        .'"RF","Bestandsnummer","Totaal aantal records","Totaal aantal bestanden"'."\n"
        .'"RF","1","0","1"'."\n"
    );

    try {
        $result = $this->importer->runFromUpload(
            $tmp,
            'paypal-csv',
            $this->fixtureUser,
            'paypal-balance-reconciliation.csv',
        );

        // A file nothing could be read from is a failure OF the file, not a
        // row inside it. It used to be reported as a single error row, which
        // put the one sentence the reader needs behind a rows table that had
        // no rows to show it in.
        expect($result->rows)->toBe([]);
        expect($result->fileFailureReason)->toBe(ImportFailureReason::FileUnreadable);
        expect($result->fileFailureDetail)->toContain('Rapport Transactiegegevens');
    } finally {
        @unlink($tmp);
    }
})->group('phase-16.1.1');
