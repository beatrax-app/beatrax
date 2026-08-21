<?php

declare(strict_types=1);

use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ledger\Models\Transaction;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);
    $this->csvFixture = base_path('tests/fixtures/asn-cross-format/february.csv');
    $this->camtFixture = base_path('tests/fixtures/asn-cross-format/february.camt053.xml');
    $this->mt940Fixture = base_path('tests/fixtures/asn-cross-format/february.mt940.sta');
    $this->expectedTransactionCount = 72;
});

// Statement-vs-statement collisions drop as DUPLICATE and never enrich: the
// rank-based source_ref upgrade only fires when one side is a receipt format,
// which can carry data a statement lacks. Re-uploading one period in two
// statement formats must not add a second source_format to the audit chain.

it('csv_then_camt053: every overlapping row drops as duplicate (no enrichment)', function (): void {
    $first = $this->importer->runAndConfirm($this->csvFixture, 'asn-csv', $this->fixtureUser, formatHint: BankCsvFormatHint::Asn);
    expect($first->inserted)->toBe($this->expectedTransactionCount);
    expect($first->enriched)->toBe(0);

    $second = $this->importer->runAndConfirm($this->camtFixture, 'camt053', $this->fixtureUser);

    expect($second->inserted)->toBe(0);
    expect($second->enriched)->toBe(0);
    expect($second->duplicates)->toBe($this->expectedTransactionCount);

    $totalCsv = Transaction::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('source_format', 'asn-csv')
        ->count();
    expect($totalCsv)->toBe($this->expectedTransactionCount);

    $rowsWithProvenance = Transaction::query()
        ->where('user_id', $this->fixtureUser->id)
        ->whereNotNull('enriched_from')
        ->count();
    expect($rowsWithProvenance)->toBe(0);
})->group('phase-2');

it('camt053_then_csv: every overlapping row drops as duplicate (no enrichment)', function (): void {
    $first = $this->importer->runAndConfirm($this->camtFixture, 'camt053', $this->fixtureUser);
    expect($first->inserted)->toBe($this->expectedTransactionCount);
    expect($first->enriched)->toBe(0);

    $second = $this->importer->runAndConfirm($this->csvFixture, 'asn-csv', $this->fixtureUser, formatHint: BankCsvFormatHint::Asn);

    expect($second->inserted)->toBe(0);
    expect($second->enriched)->toBe(0);
    expect($second->duplicates)->toBe($this->expectedTransactionCount);

    $totalCamt = Transaction::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('source_format', 'camt053')
        ->count();
    expect($totalCamt)->toBe($this->expectedTransactionCount);

    $rowsWithProvenance = Transaction::query()
        ->where('user_id', $this->fixtureUser->id)
        ->whereNotNull('enriched_from')
        ->count();
    expect($rowsWithProvenance)->toBe(0);
})->group('phase-2');

it('same_format_replay: re-importing CAMT after CAMT produces zero new rows and zero enrichments', function (): void {
    $first = $this->importer->runAndConfirm($this->camtFixture, 'camt053', $this->fixtureUser);
    expect($first->inserted)->toBe($this->expectedTransactionCount);

    $second = $this->importer->runAndConfirm($this->camtFixture, 'camt053', $this->fixtureUser);

    expect($second->inserted)->toBe(0);
    expect($second->enriched)->toBe(0);
})->group('phase-2');

it('mt940_then_camt053: every overlapping row drops as duplicate (no enrichment)', function (): void {
    if (! file_exists($this->mt940Fixture)) {
        $this->markTestSkipped('No same-period MT940 export available from ASN — see asn-cross-format/README.md');
    }

    $this->importer->runAndConfirm($this->mt940Fixture, 'mt940', $this->fixtureUser);
    $second = $this->importer->runAndConfirm($this->camtFixture, 'camt053', $this->fixtureUser);

    expect($second->inserted)->toBe(0);
    expect($second->enriched)->toBe(0);
    expect($second->duplicates)->toBeGreaterThan(0);
})->group('phase-2');

it('camt053_then_mt940: every overlapping row drops as duplicate', function (): void {
    if (! file_exists($this->mt940Fixture)) {
        $this->markTestSkipped('No same-period MT940 export available from ASN — see asn-cross-format/README.md');
    }

    $this->importer->runAndConfirm($this->camtFixture, 'camt053', $this->fixtureUser);
    $second = $this->importer->runAndConfirm($this->mt940Fixture, 'mt940', $this->fixtureUser);

    expect($second->inserted)->toBe(0);
    expect($second->enriched)->toBe(0);
    expect($second->duplicates)->toBeGreaterThan(0);
})->group('phase-2');

it('preview-only flow surfaces every cross-statement collision as duplicate', function (): void {
    $this->importer->runAndConfirm($this->csvFixture, 'asn-csv', $this->fixtureUser, formatHint: BankCsvFormatHint::Asn);

    $preview = $this->importer->runFromUpload(
        $this->camtFixture,
        'camt053',
        $this->fixtureUser,
        'february.camt053.xml',
    );

    $enrichedRows = array_filter($preview->rows, fn ($r) => $r->status === PreviewRowStatus::Enriched);
    expect($enrichedRows)->toBeEmpty();

    $duplicateRows = array_filter($preview->rows, fn ($r) => $r->status === PreviewRowStatus::Duplicate);
    expect(count($duplicateRows))->toBe($this->expectedTransactionCount);

    foreach ($duplicateRows as $row) {
        expect($row->diff)->toBeNull();
    }
})->group('phase-2');

it('cross_format_pair_fingerprints_match: every CSV row has a CAMT counterpart with the same v3 fingerprint', function (): void {
    // The v3 tuple carries no source_format, so both exports of one row hash
    // alike. Only the disposition on match changed: DUPLICATE, not ENRICHED.
    $first = $this->importer->runAndConfirm($this->csvFixture, 'asn-csv', $this->fixtureUser, formatHint: BankCsvFormatHint::Asn);
    expect($first->inserted)->toBe($this->expectedTransactionCount);

    $preview = $this->importer->runFromUpload(
        $this->camtFixture,
        'camt053',
        $this->fixtureUser,
        'february.camt053.xml',
    );

    $newRows = array_filter($preview->rows, fn ($r) => $r->status === PreviewRowStatus::NewRow);
    $errorRows = array_filter($preview->rows, fn ($r) => $r->status === PreviewRowStatus::Error);

    expect($newRows)->toBeEmpty();
    expect($errorRows)->toBeEmpty();

    foreach ($preview->rows as $row) {
        expect($row->status)->toBe(PreviewRowStatus::Duplicate);
    }
})->group('phase-2');
