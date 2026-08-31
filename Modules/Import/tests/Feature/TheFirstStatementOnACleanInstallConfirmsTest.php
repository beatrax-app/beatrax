<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;
use Modules\Core\Public\Services\NavCountsService;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;

// `import_runs` is one of the tables a write to invalidates the sidebar badges,
// and the badge listener writes a `cache` row from inside that INSERT's own
// event. ImportRun::create() ends in insertGetId(), which reads lastInsertId() —
// per connection — so the preview named a run the confirm could not find.

// The phones put the cache in the database, on the connection every other
// statement uses (mobile-app/bootstrap/app.php), and so does a self-hosted
// server. The suite runs it in an array and cannot see the interleave at all,
// so the store goes back where the device keeps it.
beforeEach(function (): void {
    config(['cache.default' => 'database']);
    app()->forgetInstance('cache.store');
    app()->forgetInstance(CacheRepository::class);
    app()->forgetInstance(NavCountsService::class);
    app('cache')->forgetDriver(['array', 'database']);

    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->fixture = __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv';

    // The generation key is what decides whether the bump allocates a rowid.
    // increment() answers false only while the row is absent, and only then does
    // the forever() fallback INSERT it; once it exists every bump is an UPDATE.
    $this->generationRowId = static fn (): ?int => DB::selectOne(
        'select rowid as id from cache where key like ?',
        ['%nav-counts:generation'],
    )?->id;

    // Reading the badges takes cache rowid 1 without creating the generation
    // key, which is the second half of the condition: a cache table that is
    // still empty hands the bump the same rowid the run was given, and the
    // wrong answer is accidentally the right number.
    app(NavCountsService::class)->forUser($this->fixtureUser->id);
});

it('previews under the import run row the upload actually opened', function (): void {
    $absentBefore = ($this->generationRowId)();

    $preview = app(RunsImports::class)->runFromUpload(
        $this->fixture,
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    $rowId = (int) DB::table('import_runs')->where('user_id', $this->fixtureUser->id)->value('id');

    // Both halves of the condition, asserted rather than assumed: the bump had
    // to INSERT its key, and the rowid it took has to differ from the run's id.
    expect($absentBefore)->toBeNull()
        ->and(($this->generationRowId)())->not->toBe($rowId)
        ->and($preview->importRunId)->toBe($rowId);
});

it('confirms the first statement a clean install imports', function (): void {
    $preview = app(RunsImports::class)->runFromUpload(
        $this->fixture,
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    $result = app(ConfirmsImports::class)($preview->importRunId, $this->fixtureUser);

    expect($result->inserted)->toBeGreaterThan(0);
});
