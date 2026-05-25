<?php

declare(strict_types=1);

use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
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

/*
 * Cross-format dedup policy (phase 16 UAT batch 7):
 *
 * Statement-vs-statement collisions (asn-csv / asn-camt053 / asn-mt940
 * / ics-pdf / paypal-csv) drop as DUPLICATE on fingerprint match —
 * never enrich. The rank-based source_ref upgrade only fires when AT
 * LEAST one side is a receipt format (paypal-receipt, ics-receipt,
 * google-play-receipt); receipts can legitimately carry data the bank
 * statement lacks (clean merchant name, line items) so the upgrade
 * pay-off survives there.
 *
 * Prior phase-2 tests asserted the inverse (CSV → CAMT enriched every
 * row). The user's phase-16 UAT report demanded the simpler behaviour
 * — same transaction → drop — because re-uploading the same date range
 * in two statement formats should not pollute the row's audit chain
 * with a second source_format. These tests lock the new policy.
 */

it('csv_then_camt053: every overlapping row drops as duplicate (no enrichment)', function (): void {
    $first = $this->importer->runAndConfirm($this->csvFixture, 'asn-csv', $this->fixtureUser, formatHint: BankCsvFormatHint::Asn);
    expect($first->inserted)->toBe($this->expectedTransactionCount);
    expect($first->enriched)->toBe(0);

    $second = $this->importer->runAndConfirm($this->camtFixture, 'asn-camt053', $this->fixtureUser);

    expect($second->inserted)->toBe(0);
    expect($second->enriched)->toBe(0);
    expect($second->duplicates)->toBe($this->expectedTransactionCount);

    // The original asn-csv rows are untouched — no source_format flip,
    // no enriched_from entry, no source_ref upgrade.
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
    $first = $this->importer->runAndConfirm($this->camtFixture, 'asn-camt053', $this->fixtureUser);
    expect($first->inserted)->toBe($this->expectedTransactionCount);
    expect($first->enriched)->toBe(0);

    $second = $this->importer->runAndConfirm($this->csvFixture, 'asn-csv', $this->fixtureUser, formatHint: BankCsvFormatHint::Asn);

    expect($second->inserted)->toBe(0);
    expect($second->enriched)->toBe(0);
    expect($second->duplicates)->toBe($this->expectedTransactionCount);

    // The original asn-camt053 rows are untouched — no source_format flip,
    // no enriched_from entry, no source_ref upgrade.
    $totalCamt = Transaction::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('source_format', 'asn-camt053')
        ->count();
    expect($totalCamt)->toBe($this->expectedTransactionCount);

    $rowsWithProvenance = Transaction::query()
        ->where('user_id', $this->fixtureUser->id)
        ->whereNotNull('enriched_from')
        ->count();
    expect($rowsWithProvenance)->toBe(0);
})->group('phase-2');

it('same_format_replay: re-importing CAMT after CAMT produces zero new rows and zero enrichments', function (): void {
    $first = $this->importer->runAndConfirm($this->camtFixture, 'asn-camt053', $this->fixtureUser);
    expect($first->inserted)->toBe($this->expectedTransactionCount);

    $second = $this->importer->runAndConfirm($this->camtFixture, 'asn-camt053', $this->fixtureUser);

    expect($second->inserted)->toBe(0);
    expect($second->enriched)->toBe(0);
})->group('phase-2');

it('mt940_then_camt053: every overlapping row drops as duplicate (no enrichment)', function (): void {
    if (! file_exists($this->mt940Fixture)) {
        $this->markTestSkipped('No same-period MT940 export available from ASN — see asn-cross-format/README.md');
    }

    $this->importer->runAndConfirm($this->mt940Fixture, 'asn-mt940', $this->fixtureUser);
    $second = $this->importer->runAndConfirm($this->camtFixture, 'asn-camt053', $this->fixtureUser);

    expect($second->inserted)->toBe(0);
    expect($second->enriched)->toBe(0);
    expect($second->duplicates)->toBeGreaterThan(0);
})->group('phase-2');

it('camt053_then_mt940: every overlapping row drops as duplicate', function (): void {
    if (! file_exists($this->mt940Fixture)) {
        $this->markTestSkipped('No same-period MT940 export available from ASN — see asn-cross-format/README.md');
    }

    $this->importer->runAndConfirm($this->camtFixture, 'asn-camt053', $this->fixtureUser);
    $second = $this->importer->runAndConfirm($this->mt940Fixture, 'asn-mt940', $this->fixtureUser);

    expect($second->inserted)->toBe(0);
    expect($second->enriched)->toBe(0);
    expect($second->duplicates)->toBeGreaterThan(0);
})->group('phase-2');

it('preview-only flow surfaces every cross-statement collision as duplicate', function (): void {
    $this->importer->runAndConfirm($this->csvFixture, 'asn-csv', $this->fixtureUser, formatHint: BankCsvFormatHint::Asn);

    $preview = $this->importer->runFromUpload(
        $this->camtFixture,
        'asn-camt053',
        $this->fixtureUser,
        'february.camt053.xml',
    );

    $enrichedRows = array_filter($preview->rows, fn ($r) => $r->status === 'enriched');
    expect($enrichedRows)->toBeEmpty();

    $duplicateRows = array_filter($preview->rows, fn ($r) => $r->status === 'duplicate');
    expect(count($duplicateRows))->toBe($this->expectedTransactionCount);

    foreach ($duplicateRows as $row) {
        expect($row->diff)->toBeNull();
    }
})->group('phase-2');

it('cross_format_pair_fingerprints_match: every CSV row has a CAMT counterpart with the same v3 fingerprint', function (): void {
    // Fingerprint parity still holds — the source_format-independent
    // tuple (user_id, account_id, posted_at, booked_at, amount_minor,
    // currency, counterparty_normalized) hashes the same regardless of
    // which export produced the row. The only change is the disposition
    // we now return on match: DUPLICATE instead of ENRICHED.
    $first = $this->importer->runAndConfirm($this->csvFixture, 'asn-csv', $this->fixtureUser, formatHint: BankCsvFormatHint::Asn);
    expect($first->inserted)->toBe($this->expectedTransactionCount);

    $preview = $this->importer->runFromUpload(
        $this->camtFixture,
        'asn-camt053',
        $this->fixtureUser,
        'february.camt053.xml',
    );

    $newRows = array_filter($preview->rows, fn ($r) => $r->status === 'new');
    $errorRows = array_filter($preview->rows, fn ($r) => $r->status === 'error');

    expect($newRows)->toBeEmpty();
    expect($errorRows)->toBeEmpty();

    // Every preview row is a duplicate under the new policy.
    foreach ($preview->rows as $row) {
        expect($row->status)->toBe('duplicate');
    }
})->group('phase-2');
