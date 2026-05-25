<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;

/**
 * Locks in the third-tier description fallback in the preview-wizard
 * Counterparty column. ASN bank-fee / interest / ATM rows arrive with no
 * counterparty name AND no counterparty IBAN; before this fix the cell
 * rendered "—" and the operator could not tell what the row was.
 *
 * The fixture `asn-sample-1.csv` includes several such rows (rente over
 * negatief saldo, fuel BEA pin payments, etc.). The wizard now surfaces
 * the joined payment-reference + description as italic muted text so the
 * row is identifiable without clicking through.
 */
beforeEach(function (): void {
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
        // A literal substring from one of the description-only rows in
        // the fixture (the BEA fuel-pump payment narrative).
        ->assertSee('SHELL PIETER NIEUW');
});
