<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\ImportRun;

uses(RefreshDatabase::class);

// The staged path is the content hash, so re-staging the same file resolves to
// the same path. `Storage::put()` truncates that path before the first new byte
// lands, emptying a file the running parser already holds open — read as a CSV
// with no rows, reported as zero inserted rather than as a failure.
beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);
    $this->fixture = __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv';
});

// Asserted on the inode because the symptom is invisible from one process: both
// writers carry identical bytes, so a finished truncating rewrite looks atomic.
// Writing in place keeps the inode; publishing by rename replaces it, which is
// what leaves an already-open descriptor pointing at a whole file.
it('publishes a re-staged file by rename rather than writing over it', function (): void {
    // A confirmed SHA short-circuits before staging, so only the re-preview
    // path rewrites the file — what a second tab or a retried upload takes.
    $first = $this->importer->runFromUpload(
        $this->fixture,
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    $staged = (string) ImportRun::query()->findOrFail($first->importRunId)->raw_file_path;

    expect($staged)->toBeFile();

    $expected = (string) file_get_contents($staged);
    expect($expected)->not->toBe('');

    clearstatcache(true, $staged);
    $before = fileinode($staged);

    if ($before === false || $before === 0) {
        $this->markTestSkipped('filesystem does not report inodes');
    }

    // Held open across the re-stage, as the parser holds it while reading.
    $reader = fopen($staged, 'rb');
    expect($reader)->not->toBeFalse();

    $this->importer->runFromUpload(
        $this->fixture,
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    clearstatcache(true, $staged);

    expect(fileinode($staged))->not->toBe($before, 'a re-stage must publish a new inode, never rewrite the one readers hold');

    $held = (string) stream_get_contents($reader);
    fclose($reader);

    expect($held)->toBe($expected)
        ->and((string) file_get_contents($staged))->toBe($expected);
});

it('leaves no partial staging files behind', function (): void {
    $result = $this->importer->runAndConfirm(
        $this->fixture,
        'asn-csv',
        $this->fixtureUser,
        formatHint: BankCsvFormatHint::Asn,
    );

    $staged = (string) ImportRun::query()->findOrFail($result->importRunId)->raw_file_path;
    $leftovers = glob(dirname($staged).'/*.part');

    expect($leftovers)->toBe([]);
});
