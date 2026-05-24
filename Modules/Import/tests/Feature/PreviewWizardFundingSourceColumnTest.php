<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\RunsImports;

/**
 * Locks in the "Funding source" column on the preview-wizard table:
 *  - header label reads "Funding source" (the old "Source" label is gone).
 *  - the cell renders the counterparty IBAN from the source row.
 *  - the cell renders an em-dash when the source row carries no
 *    counterparty IBAN (typical for bank-fee / interest / ATM rows
 *    where ASN never populates a counterparty IBAN).
 *
 * The asn-sample-1 fixture deliberately mixes both shapes — peer-to-peer
 * transfers with a populated counterparty IBAN AND interest / fee rows
 * with none — so a single preview exercises both render paths.
 */
beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('renames the column header to "Funding source"', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
    );

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertSee('Funding source')
        ->assertDontSee('>Source<', false);
});

it('renders the counterparty IBAN in the Funding source cell', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
    );

    // Pick any preview row that actually carries a counterparty IBAN
    // (peer-to-peer transfers populate this; bank-fee rows do not).
    $rowWithIban = null;
    foreach ($preview->rows as $row) {
        if ($row->counterpartyIban !== null) {
            $rowWithIban = $row;

            break;
        }
    }

    expect($rowWithIban)->not->toBeNull();

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertSee($rowWithIban->counterpartyIban);
});

it('renders an em-dash when the source row carries no counterparty IBAN', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
    );

    // The fixture must contain at least one row without a counterparty
    // IBAN (interest / fee row) for the em-dash render to be exercised.
    $rowWithoutIban = null;
    foreach ($preview->rows as $row) {
        if ($row->counterpartyIban === null) {
            $rowWithoutIban = $row;

            break;
        }
    }

    expect($rowWithoutIban)->not->toBeNull();

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertSee('—');
});
