<?php

declare(strict_types=1);

use Modules\Import\Public\Contracts\RunsImports;

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
    expect(array_filter($result->rows, fn ($r) => $r->status === 'error'))->toBe([]);
})->group('phase-16.1.1');

it('surfaces an actionable single-row preview error when the user uploads a PayPal Saldorapport by mistake', function (): void {
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

        expect(count($result->rows))->toBe(1);
        expect($result->rows[0]->status)->toBe('error');
        expect($result->rows[0]->error)->toContain('Rapport Transactiegegevens');
    } finally {
        @unlink($tmp);
    }
})->group('phase-16.1.1');
