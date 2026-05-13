<?php

declare(strict_types=1);

use Modules\Import\Public\Contracts\RunsImports;
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

it('csv_then_camt053: importing CAMT after CSV inserts 0 new and enriches every row', function (): void {
    $first = $this->importer->runAndConfirm($this->csvFixture, 'asn-csv', $this->fixtureUser);
    expect($first->inserted)->toBe($this->expectedTransactionCount);
    expect($first->enriched)->toBe(0);

    $second = $this->importer->runAndConfirm($this->camtFixture, 'asn-camt053', $this->fixtureUser);

    expect($second->inserted)->toBe(0);
    expect($second->enriched)->toBeGreaterThan(0);
    expect($second->enriched + $second->duplicates)->toBe($this->expectedTransactionCount);

    // On-disk row count is unchanged after the cross-format re-import.
    $totalCsv = Transaction::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('source_format', 'asn-csv')
        ->count();
    expect($totalCsv)->toBe($this->expectedTransactionCount);

    // Enriched rows now carry a CAMT EndToEndId as source_ref and an
    // asn-camt053 entry in enriched_from.
    $enrichedRows = Transaction::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('source_format', 'asn-csv')
        ->whereNotNull('enriched_from')
        ->get();

    expect($enrichedRows->count())->toBe($second->enriched);
    foreach ($enrichedRows as $row) {
        /** @var ArrayObject<int, array<string, mixed>> $prov */
        $prov = $row->enriched_from;
        $entries = $prov->getArrayCopy();
        expect($entries)->not->toBeEmpty();
        $formats = array_map(static fn (array $e): string => (string) $e['format'], $entries);
        expect($formats)->toContain('asn-camt053');
    }
})->group('phase-2');

it('camt053_then_csv: importing CSV after CAMT inserts 0 new and never inserts duplicates', function (): void {
    $first = $this->importer->runAndConfirm($this->camtFixture, 'asn-camt053', $this->fixtureUser);
    expect($first->inserted)->toBe($this->expectedTransactionCount);
    expect($first->enriched)->toBe(0);

    // Snapshot the CAMT rows that already carry an EndToEndId (rank 4).
    // For those rows, a CSV Volgnummer (rank 1) can never enrich. For
    // CAMT rows that lacked an EndToEndId (NULL source_ref, rank 0),
    // a non-null CSV ref is legitimately stronger per the cross-format
    // enrichment contract (non-null > null).
    $camtRowsWithRef = Transaction::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('source_format', 'asn-camt053')
        ->whereNotNull('source_ref')
        ->count();
    $camtRowsWithoutRef = $this->expectedTransactionCount - $camtRowsWithRef;

    $second = $this->importer->runAndConfirm($this->csvFixture, 'asn-csv', $this->fixtureUser);

    expect($second->inserted)->toBe(0);
    expect($second->duplicates)->toBe($camtRowsWithRef);
    expect($second->enriched)->toBe($camtRowsWithoutRef);
    expect($second->duplicates + $second->enriched)->toBe($this->expectedTransactionCount);

    // Total on-disk row count is unchanged.
    $totalCamt = Transaction::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('source_format', 'asn-camt053')
        ->count();
    expect($totalCamt)->toBe($this->expectedTransactionCount);

    // Any provenance entry written during the CSV replay records the
    // enriching format as `asn-csv`.
    if ($second->enriched > 0) {
        $rowsWithCsvProv = Transaction::query()
            ->where('user_id', $this->fixtureUser->id)
            ->whereNotNull('enriched_from')
            ->get()
            ->filter(function (Transaction $row): bool {
                /** @var ArrayObject<int, array<string, mixed>> $prov */
                $prov = $row->enriched_from;
                foreach ($prov->getArrayCopy() as $entry) {
                    if (($entry['format'] ?? null) === 'asn-csv') {
                        return true;
                    }
                }

                return false;
            })
            ->count();
        expect($rowsWithCsvProv)->toBe($second->enriched);
    }
})->group('phase-2');

it('same_format_replay: re-importing CAMT after CAMT produces zero new rows and zero enrichments', function (): void {
    $first = $this->importer->runAndConfirm($this->camtFixture, 'asn-camt053', $this->fixtureUser);
    expect($first->inserted)->toBe($this->expectedTransactionCount);

    $second = $this->importer->runAndConfirm($this->camtFixture, 'asn-camt053', $this->fixtureUser);

    expect($second->inserted)->toBe(0);
    expect($second->enriched)->toBe(0);
})->group('phase-2');

it('mt940_then_camt053: importing CAMT after MT940 enriches MT940 rows', function (): void {
    if (! file_exists($this->mt940Fixture)) {
        $this->markTestSkipped('No same-period MT940 export available from ASN — see asn-cross-format/README.md');
    }

    $this->importer->runAndConfirm($this->mt940Fixture, 'asn-mt940', $this->fixtureUser);
    $second = $this->importer->runAndConfirm($this->camtFixture, 'asn-camt053', $this->fixtureUser);

    expect($second->inserted)->toBe(0);
    expect($second->enriched)->toBeGreaterThan(0);
})->group('phase-2');

it('camt053_then_mt940: importing MT940 after CAMT produces only duplicates (MT940 is weaker)', function (): void {
    if (! file_exists($this->mt940Fixture)) {
        $this->markTestSkipped('No same-period MT940 export available from ASN — see asn-cross-format/README.md');
    }

    $this->importer->runAndConfirm($this->camtFixture, 'asn-camt053', $this->fixtureUser);
    $second = $this->importer->runAndConfirm($this->mt940Fixture, 'asn-mt940', $this->fixtureUser);

    expect($second->inserted)->toBe(0);
    expect($second->enriched)->toBe(0);
    expect($second->duplicates)->toBeGreaterThan(0);
})->group('phase-2');

it('preview-only flow surfaces enriched rows with the diff field', function (): void {
    $this->importer->runAndConfirm($this->csvFixture, 'asn-csv', $this->fixtureUser);

    $preview = $this->importer->runFromUpload(
        $this->camtFixture,
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

it('cross_format_pair_fingerprints_match: every CSV row has a CAMT counterpart with the same v3 fingerprint', function (): void {
    // Import the CSV side, capture its fingerprints, then preview the
    // CAMT side and verify every CSV fingerprint is hit either as a
    // duplicate (same source_format ranking and ref value) or an
    // enrichment (CAMT outranks CSV) — both prove v3 fingerprint
    // stability across the two ingestion paths.
    $first = $this->importer->runAndConfirm($this->csvFixture, 'asn-csv', $this->fixtureUser);
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

    // Every preview row is either duplicate or enriched — i.e. every
    // CAMT row hits an existing CSV fingerprint.
    foreach ($preview->rows as $row) {
        expect($row->status)->toBeIn(['duplicate', 'enriched']);
    }
})->group('phase-2');
