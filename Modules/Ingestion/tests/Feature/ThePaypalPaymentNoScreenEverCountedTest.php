<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Import\Public\Enums\PreviewRowStatus;

// A PayPal export is read whole before a single row is yielded, so a payment
// the rollup cannot read is gone before the pipeline has seen anything to
// count. The preview's own "Rows skipped" line is what the reader is owed.

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->importer = $this->app->make(RunsImports::class);

    $this->paypalCsv = function (string $secondPaymentGross): string {
        $header = 'Datum,Tijd,Tijdzone,Omschrijving,Valuta,"Bruto ","Kosten ",Netto,Saldo,'
            .'Transactiereferentie,"Van e-mailadres",Naam,"Naam bank",Bankrekening,Verzendkosten,'
            .'Btw,Factuurreferentie,"Reference Txn ID"';

        $payment = static fn (string $ref, string $gross): string => '4/1/2026,11:56:08,Europe/Berlin,'
            .'"Vooraf goedgekeurde betaling – rekening betaald door gebruiker",EUR,'
            .'"'.$gross.'","0,00","'.$gross.'","0,00",'.$ref.',kaarthouder@example.test,'
            .'"Google Cloud EMEA Limited",,,"0,00","0,00",,';

        $tmp = tempnam(sys_get_temp_dir(), 'paypal-unreadable-').'.csv';
        file_put_contents($tmp, implode("\n", [
            $header,
            $payment('O-00000000000000001', '-8,10'),
            $payment('O-00000000000000002', $secondPaymentGross),
            $payment('O-00000000000000003', '-3,00'),
        ])."\n");

        return $tmp;
    };

    $this->paypalCsvWithoutGross = static function (): string {
        $header = 'Datum,Tijd,Tijdzone,Omschrijving,Valuta,"Kosten ",Netto,Saldo,'
            .'Transactiereferentie,"Van e-mailadres",Naam,"Naam bank",Bankrekening,Verzendkosten,'
            .'Btw,Factuurreferentie,"Reference Txn ID"';

        $payment = static fn (string $ref): string => '4/1/2026,11:56:08,Europe/Berlin,'
            .'"Vooraf goedgekeurde betaling – rekening betaald door gebruiker",EUR,'
            .'"0,00","-8,10","0,00",'.$ref.',kaarthouder@example.test,'
            .'"Google Cloud EMEA Limited",,,"0,00","0,00",,';

        $tmp = tempnam(sys_get_temp_dir(), 'paypal-no-gross-').'.csv';
        file_put_contents($tmp, implode("\n", [
            $header,
            $payment('O-00000000000000001'),
            $payment('O-00000000000000002'),
        ])."\n");

        return $tmp;
    };
});

it('counts a payment it could not read among the rows the preview says were skipped', function (): void {
    $csv = ($this->paypalCsv)('NOT-A-NUMBER');

    try {
        $result = $this->importer->runFromUpload($csv, 'paypal-csv', $this->fixtureUser, 'paypal-unreadable.csv');

        expect($result->errorRows())->toBe(1);
        expect($result->totalRows())->toBe(3);
        expect($result->importableRows())->toBe(2);
    } finally {
        @unlink($csv);
    }
});

it('shows the payment it could not read as a row of its own, with no day and no amount', function (): void {
    $csv = ($this->paypalCsv)('NOT-A-NUMBER');

    try {
        $result = $this->importer->runFromUpload($csv, 'paypal-csv', $this->fixtureUser, 'paypal-unreadable.csv');

        $errors = array_values(array_filter(
            $result->rows,
            static fn ($row): bool => $row->status === PreviewRowStatus::Error,
        ));

        expect($errors)->toHaveCount(1);
        expect($errors[0]->errorReason)->toBe(ImportFailureReason::RowUnreadable);
        expect($errors[0]->postedAt)->toBeNull();
        expect($errors[0]->amountMinor)->toBeNull();
    } finally {
        @unlink($csv);
    }
});

it('publishes no closing balance for a statement it could not read every payment of', function (): void {
    $csv = ($this->paypalCsv)('NOT-A-NUMBER');

    try {
        $this->importer->runFromUpload($csv, 'paypal-csv', $this->fixtureUser, 'paypal-unreadable.csv');

        $summary = $this->app->make(DatabaseManager::class)->connection()
            ->table('statement_summaries')
            ->where('user_id', $this->fixtureUser->id)
            ->first();

        expect($summary)->not->toBeNull();
        expect($summary->closing_balance_minor)->toBeNull();
        expect($summary->opening_balance_minor)->toBeNull();
    } finally {
        @unlink($csv);
    }
});

// The amount column was not part of what made a file a PayPal statement, so an
// export without one was accepted and every payment in it read as 0,00 — an
// import that reported itself clean and added a wallet's worth of nothing.
it('refuses a PayPal export that carries no gross-amount column instead of reading it as zeroes', function (): void {
    $csv = ($this->paypalCsvWithoutGross)();

    try {
        $result = $this->importer->runFromUpload($csv, 'paypal-csv', $this->fixtureUser, 'paypal-no-gross.csv');

        expect($result->rows)->toHaveCount(0);
        expect($result->fileFailureReason)->toBe(ImportFailureReason::FileUnreadable);
        expect($result->fileFailureDetail)->toContain('language profile');
    } finally {
        @unlink($csv);
    }
});

it('publishes the summed closing balance when every payment in the file was read', function (): void {
    $csv = ($this->paypalCsv)('-2,00');

    try {
        $result = $this->importer->runFromUpload($csv, 'paypal-csv', $this->fixtureUser, 'paypal-readable.csv');

        $summary = $this->app->make(DatabaseManager::class)->connection()
            ->table('statement_summaries')
            ->where('user_id', $this->fixtureUser->id)
            ->first();

        expect($result->errorRows())->toBe(0);
        expect($result->totalRows())->toBe(3);
        expect($summary)->not->toBeNull();
        expect($summary->closing_balance_minor)->toBe(-1310);
    } finally {
        @unlink($csv);
    }
});
