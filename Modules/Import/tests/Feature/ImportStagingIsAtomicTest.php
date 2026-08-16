<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\ImportRun;

uses(RefreshDatabase::class);

/*
 * Staging an upload publishes the file atomically.
 *
 * The staged path is the content hash under the owning user
 * (`imports/{user_id}/{sha256}.csv`), so the same file imported twice
 * resolves to the same absolute path — and `runFromUpload()` hands that
 * exact path to the parser immediately after writing it.
 *
 * `Storage::put()` opens the destination for writing, which truncates it
 * before a byte of new content lands. A second import of the same file by
 * the same user therefore had a window where the first one's parser opened
 * a file that had just been emptied — read as a CSV with no rows, so the
 * import reported zero inserted rather than failing. It surfaced as a
 * parallel-suite flake, four test processes sharing one `storage/app` and
 * all staging the same fixture as user 1, but two browser tabs or a retried
 * upload reach it the same way.
 *
 * Both writers always carry identical bytes — the name IS the hash — so
 * atomicity is the whole fix: a reader sees one complete copy or the other.
 */
beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);
    $this->fixture = __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv';
});

// Asserted on the inode, because the symptom itself is not observable from
// one process: both writers carry identical bytes, so once a truncating
// rewrite finishes the result is indistinguishable from an atomic one — only
// a reader landing inside the window sees the empty file, and that needs a
// second process. The inode is the mechanism that closes the window. Writing
// in place keeps it; publishing by rename replaces it, which is precisely
// what leaves an already-open descriptor pointing at a whole file.
it('publishes a re-staged file by rename rather than writing over it', function (): void {
    // Previews, not confirmed imports. A confirmed SHA short-circuits before
    // staging is reached, so only the re-preview path re-writes the file —
    // which is the path a second tab, a retried upload, and a parallel test
    // process all take.
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

    // Held open across the re-stage, exactly as the parser holds it open
    // while reading.
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
