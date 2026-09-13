<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Public\Support\RenderedMarkup;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Import\Public\Enums\EnrichmentConflictField;
use Modules\Import\Public\Enums\PreviewRowStatus;

// Walked at 1440x900 and 390x844 on /imports/{id}/preview: the enriched row
// printed `source_ref: 4471902 → 4471902` and said nothing about the amount,
// which is −€12.99 against −€14.99 and the only thing about the row that
// disagrees. A restatement is matched ON the reference, so the two sides of
// that line are identical by construction — the preview drew the one field
// that could not have moved as the change, and hid the one that did.

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);

    $fixtures = __DIR__.'/../../../../tests/fixtures/';
    $importer = $this->app->make(RunsImports::class);

    $importer->runAndConfirm($fixtures.'asn-a-card-row-at-the-terminals-price.csv', 'asn-csv', $this->fixtureUser, formatHint: BankCsvFormatHint::Asn);

    $this->restated = $importer->runFromUpload(
        $fixtures.'asn-the-same-card-row-restated-with-the-tip.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-the-same-card-row-restated-with-the-tip.csv',
        BankCsvFormatHint::Asn,
    );

    $this->enriched = array_values(array_filter(
        $this->restated->rows,
        static fn ($row): bool => $row->status === PreviewRowStatus::Enriched,
    ));
});

// The positive control: without an enriched row every question below is asked
// of a preview that has nothing to say.
it('previews the restating row as an enrichment', function (): void {
    expect($this->enriched)->toHaveCount(1);
});

it('names the amount the restatement changes', function (): void {
    expect($this->enriched[0]->diff)->toHaveKey(EnrichmentConflictField::AmountMinor->value)
        ->and($this->enriched[0]->diff[EnrichmentConflictField::AmountMinor->value])
        ->toBe(['from' => '-1299', 'to' => '-1499']);
});

it('never draws the reference it matched on as a change', function (): void {
    expect($this->enriched[0]->diff)->not->toHaveKey('source_ref');
});

it('shows the reader both figures rather than one reference twice', function (): void {
    $preview = RenderedMarkup::of(
        Livewire::test(PreviewWizard::class, ['id' => $this->restated->importRunId])->html(),
    )->text();

    expect($preview)->toContain(EnrichmentConflictField::AmountMinor->value)
        ->and($preview)->toContain('-€12.99')
        ->and($preview)->toContain('-€14.99')
        ->and($preview)->not->toContain('4471902 → 4471902');
});
