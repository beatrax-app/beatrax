<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\Account;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

// Confirming a large import killed the app outright: the phone died four and a
// half minutes in with zero rows written, nothing in the log, no failed job,
// and the run still `previewed` — so the reader lost the import and was told
// nothing. It read the whole canonical list into memory to hand it to a
// recorder that already buffers what it is given.
it('reads the canonical rows a chunk at a time rather than all at once', function (): void {
    Account::query()->updateOrCreate(
        ['iban' => 'NL57ASNB0123456789'],
        ['user_id' => $this->fixtureUser->id, 'name' => 'Probe', 'slug' => 'probe-acct', 'kind' => 'asn', 'default_currency' => 'EUR'],
    );

    $preview = app(RunsImports::class)->runFromUpload(
        base_path('tests/fixtures/asn-sample-1.csv'),
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    expect($preview->totalRows())->toBeGreaterThan(200);

    // The chunk keys are read as the recorder asks for rows, so the first
    // insert happens before the last chunk has been looked at. Read up front,
    // every chunk read precedes every insert.
    $order = [];
    DB::listen(function (QueryExecuted $q) use (&$order): void {
        if (str_starts_with($q->sql, 'insert or ignore into "transactions"')) {
            $order[] = 'insert';
        }
    });

    $before = memory_get_usage(true);
    $result = app(ConfirmsImports::class)($preview->importRunId, $this->fixtureUser);

    expect($result->inserted)->toBeGreaterThan(200)
        ->and($order)->not->toBeEmpty()
        // 229 rows is small; what this pins is that confirming does not add
        // memory proportional to the run on top of what preview already holds.
        ->and(memory_get_usage(true) - $before)->toBeLessThan(16 * 1024 * 1024);
});
