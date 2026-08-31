<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;

// ASN bank-fee, interest and ATM rows arrive with neither a counterparty name
// nor an IBAN, and asn-sample-1.csv carries several of them.
beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('renders the description in the Counterparty column when name and IBAN are both absent', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        // Verbatim from the fixture's BEA fuel-pump row, which has no name.
        ->assertSee('SHELL PIETER NIEUW');
});
