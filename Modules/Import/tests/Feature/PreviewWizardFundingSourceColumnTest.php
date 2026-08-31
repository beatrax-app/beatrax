<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
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
        BankCsvFormatHint::Asn,
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
        BankCsvFormatHint::Asn,
    );

    // Peer-to-peer transfers in the fixture populate this; bank-fee rows do not.
    $rowWithIban = null;
    foreach ($preview->rows as $row) {
        if ($row->counterpartyIban !== null) {
            $rowWithIban = $row;

            break;
        }
    }

    expect($rowWithIban)->not->toBeNull();
    expect($rowWithIban->counterpartyIban)->toBe('NL68BANK0000000001');

    // The literal the cell has to draw, not Iban::grouped() run twice: reading
    // the expectation out of the function under test passes for any grouping
    // it happens to produce, including one that breaks in the wrong place.
    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertSee('NL68 BANK 0000 0000 01')
        ->assertDontSee($rowWithIban->counterpartyIban);
});

it('renders an em-dash when the source row carries no counterparty IBAN', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    // The fixture's interest and fee rows are the ones ASN leaves IBAN-less.
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
