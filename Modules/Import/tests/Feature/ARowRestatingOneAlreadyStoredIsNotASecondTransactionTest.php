<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Import\Public\Enums\EnrichmentConflictField;
use Modules\Ledger\Models\Transaction;
use Modules\Receipts\Public\Actions\ApplyReceiptConflictResolution;
use Modules\Receipts\Public\Enums\ReceiptConflictChoice;
use Modules\Receipts\Public\Services\ReceiptConflictQuery;

// One card payment at Café Plein: the bank filed €12.99, the price the terminal
// held, and restated the same row at €14.99 once the tip settled — both under
// its own sequence number 4471902. The amount is hashed into the fingerprint
// and the reference is not, so February read €27.98 for one €14.99 coffee.
beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);

    $fixtures = __DIR__.'/../../../../tests/fixtures/';
    $importer = $this->app->make(RunsImports::class);

    $this->firstFile = $fixtures.'asn-a-card-row-at-the-terminals-price.csv';
    $this->restatedFile = $fixtures.'asn-the-same-card-row-restated-with-the-tip.csv';
    $this->nextStatementFile = $fixtures.'asn-the-restated-row-again-on-the-next-statement.csv';

    $this->importFile = function (string $path) use ($importer) {
        return $importer->runAndConfirm($path, 'asn-csv', $this->fixtureUser, formatHint: BankCsvFormatHint::Asn);
    };

    $this->previewFile = function (string $path) use ($importer) {
        return $importer->runFromUpload($path, 'asn-csv', $this->fixtureUser, basename($path), BankCsvFormatHint::Asn);
    };

    $this->offered = fn (): ?array => $this->app->make(ReceiptConflictQuery::class)->latestForUser($this->fixtureUser);

    ($this->importFile)($this->firstFile);
    $this->storedId = (int) DB::table('transactions')->where('user_id', $this->fixtureUser->id)->value('id');
});

it('holds the two figures the fixtures state', function (): void {
    $stored = DB::table('transactions')->where('id', $this->storedId)->first();

    expect($stored->amount_minor)->toBe(-1299);
    expect($stored->currency)->toBe('EUR');
    expect($stored->source_ref)->toBe('4471902');
    expect($stored->posted_at)->toBe('2026-02-17');
});

// The defect, stated as a total: one €14.99 coffee counted as €27.98 of
// spending, with nothing on any screen saying the two rows are one payment.
it('writes no second transaction when a row restates one already stored', function (): void {
    $result = ($this->importFile)($this->restatedFile);

    expect($result->inserted)->toBe(0);
    expect($result->enriched)->toBe(1);
    expect(Transaction::query()->where('user_id', $this->fixtureUser->id)->count())->toBe(1);
    expect((int) DB::table('transactions')->where('user_id', $this->fixtureUser->id)->sum('amount_minor'))->toBe(-1299);
});

it('records the disagreement against the row the reference named', function (): void {
    ($this->importFile)($this->restatedFile);

    $conflict = DB::table('pending_enrichment_conflicts')
        ->where('user_id', $this->fixtureUser->id)
        ->where('field_name', EnrichmentConflictField::AmountMinor->value)
        ->first();

    expect($conflict)->not->toBeNull();
    expect((int) $conflict->transaction_id)->toBe($this->storedId);
    expect($conflict->stored_value)->toBe('-1299');
    expect($conflict->incoming_value)->toBe('-1499');
    expect($conflict->resolution)->toBeNull();
});

// A statement enriching a statement-written row settles on the stored value
// unasked, and that answer would have discarded the only record there is of
// the figure the bank actually took.
it('leaves the disagreement for the reader rather than settling it', function (): void {
    ($this->importFile)($this->restatedFile);

    $offered = ($this->offered)();

    expect($offered)->not->toBeNull();
    expect($offered['field'])->toBe(EnrichmentConflictField::AmountMinor->value);
    expect($offered['storedValue'])->toBe('-1299');
    expect($offered['incomingValue'])->toBe('-1499');
    expect($offered['sourceFormat'])->toBe('asn-csv');
});

it('keeps the stored figure standing until the reader answers', function (): void {
    ($this->importFile)($this->restatedFile);

    $stored = DB::table('transactions')->where('id', $this->storedId)->first();

    expect($stored->amount_minor)->toBe(-1299);
    expect($stored->settled_amount_minor)->toBe(-1299);
    expect($stored->source_ref)->toBe('4471902');
    expect($stored->enriched_from)->toContain('asn-csv');
});

// The overlapping period every reader downloads eventually: the restated row on
// the next statement too, under its own file. A restatement that re-restates is
// a question the reader answers once a month for the life of the account.
it('writes nothing more when the restated row arrives on the next statement', function (): void {
    ($this->importFile)($this->restatedFile);
    $again = ($this->importFile)($this->nextStatementFile);

    expect($again->inserted)->toBe(0);
    expect($again->enriched)->toBe(1);
    expect(Transaction::query()->where('user_id', $this->fixtureUser->id)->count())->toBe(1);
    expect(DB::table('pending_enrichment_conflicts')->where('user_id', $this->fixtureUser->id)->count())->toBe(1);
    expect(($this->offered)())->not->toBeNull();
});

it('reads the restated row as a plain duplicate once the reader takes its figure', function (): void {
    ($this->importFile)($this->restatedFile);

    $this->app->make(ApplyReceiptConflictResolution::class)(
        $this->fixtureUser,
        ReceiptConflictChoice::PreferReceipt,
        ($this->offered)()['conflictId'],
    );

    expect((int) DB::table('transactions')->where('id', $this->storedId)->value('amount_minor'))->toBe(-1499);
    expect((int) DB::table('transactions')->where('id', $this->storedId)->value('settled_amount_minor'))->toBe(-1499);

    $preview = ($this->previewFile)($this->nextStatementFile);

    expect(array_map(static fn ($row): string => $row->status->value, $preview->rows))->toBe(['duplicate']);
    expect(Transaction::query()->where('user_id', $this->fixtureUser->id)->count())->toBe(1);
});

// The policy the button stores answers the same question for every later
// import, which is what stops the declined figure being offered again.
it('stops asking once the reader has said the stored figure stands', function (): void {
    ($this->importFile)($this->restatedFile);

    $this->app->make(ApplyReceiptConflictResolution::class)(
        $this->fixtureUser,
        ReceiptConflictChoice::PreferFirstWrite,
        ($this->offered)()['conflictId'],
    );

    ($this->importFile)($this->nextStatementFile);

    expect((int) DB::table('transactions')->where('id', $this->storedId)->value('amount_minor'))->toBe(-1299);
    expect(Transaction::query()->where('user_id', $this->fixtureUser->id)->count())->toBe(1);
    expect(($this->offered)())->toBeNull();
});

// The reference locates the row; it never overrules the reader's own assertion
// that this row and a statement already agree.
it('leaves a reconciled row alone', function (): void {
    DB::table('transactions')->where('id', $this->storedId)->update(['status' => 'reconciled']);

    ($this->importFile)($this->restatedFile);

    expect((int) DB::table('transactions')->where('id', $this->storedId)->value('amount_minor'))->toBe(-1299);
    expect(Transaction::query()->where('user_id', $this->fixtureUser->id)->count())->toBe(1);
    expect(DB::table('pending_enrichment_conflicts')->where('user_id', $this->fixtureUser->id)->count())->toBe(0);
});
