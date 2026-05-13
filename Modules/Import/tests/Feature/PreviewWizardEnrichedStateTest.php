<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ledger\Models\Transaction;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);
});

it('renders the Enriched badge for rows with status=enriched', function (): void {
    // Seed: CSV import lands first (source_ref = CSV Volgnummer).
    $this->importer->runAndConfirm(
        base_path('tests/fixtures/asn-cross-format/february.csv'),
        'asn-csv',
        $this->fixtureUser,
    );

    // Preview a CAMT covering the same period — rows are ENRICHED.
    $preview = $this->importer->runFromUpload(
        base_path('tests/fixtures/asn-cross-format/february.camt053.xml'),
        'asn-camt053',
        $this->fixtureUser,
        'february.camt053.xml',
    );

    expect($preview->enrichedCount)->toBeGreaterThan(0);

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertSee('Enriched')
        ->assertSee('source_ref:')
        ->assertSee('→');
})->group('phase-2');

it('exposes a populated source_ref diff on every enriched preview row', function (): void {
    $this->importer->runAndConfirm(
        base_path('tests/fixtures/asn-cross-format/february.csv'),
        'asn-csv',
        $this->fixtureUser,
    );

    $preview = $this->importer->runFromUpload(
        base_path('tests/fixtures/asn-cross-format/february.camt053.xml'),
        'asn-camt053',
        $this->fixtureUser,
        'february.camt053.xml',
    );

    $enrichedRows = array_filter($preview->rows, fn ($r) => $r->status === 'enriched');
    expect($enrichedRows)->not->toBeEmpty();

    foreach ($enrichedRows as $row) {
        expect($row->diff)->not->toBeNull();
        expect($row->diff)->toHaveKey('source_ref');
        expect($row->diff['source_ref'])->toHaveKey('to');
        expect($row->diff['source_ref']['to'])->not->toBe('');
    }
})->group('phase-2');

it('renders the empty-set placeholder when a preview row carries a null from-ref', function (): void {
    // Seed: import the CSV so v3 fingerprints matching the CAMT fixture
    // are persisted, then strip the CSV source_ref on every seeded row
    // so a subsequent CAMT preview produces enriched rows whose diff
    // reports `from: null`. The CSV fixture in this corpus always
    // populates Volgnummer, so the null `from` value can only be
    // synthesised by clearing the column post-seed.
    $importer = $this->importer;

    $importer->runAndConfirm(
        base_path('tests/fixtures/asn-cross-format/february.csv'),
        'asn-csv',
        $this->fixtureUser,
    );

    clearSourceRefOnSeededCsvRows();

    $preview = $importer->runFromUpload(
        base_path('tests/fixtures/asn-cross-format/february.camt053.xml'),
        'asn-camt053',
        $this->fixtureUser,
        'february.camt053.xml',
    );

    $hasNullFrom = false;
    foreach ($preview->rows as $row) {
        if ($row->status !== 'enriched' || $row->diff === null) {
            continue;
        }
        if (($row->diff['source_ref']['from'] ?? null) === null) {
            $hasNullFrom = true;
            break;
        }
    }

    expect($hasNullFrom)->toBeTrue();

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertSee('∅', false);
})->group('phase-2');

/**
 * Resets the `source_ref` column to NULL on every previously-seeded CSV
 * transaction so a follow-up CAMT preview yields enriched rows whose diff
 * carries `from: null`. The CSV format never produces a NULL Volgnummer
 * in practice, so this synthetic mutation is the only way to drive the
 * UI's empty-set placeholder code path.
 */
function clearSourceRefOnSeededCsvRows(): void
{
    Transaction::query()
        ->where('source_format', 'asn-csv')
        ->update(['source_ref' => null]);
}
