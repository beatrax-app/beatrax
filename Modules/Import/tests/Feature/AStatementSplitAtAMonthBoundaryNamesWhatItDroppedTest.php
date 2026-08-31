<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Import\Internal\Dto\ImportRowIssue;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// PayPal books the purchase late on the last day of a month and converts the
// currency the next morning, so a statement cut at the boundary strands the FX
// legs in the following file, pointing at a parent that is not in it.
beforeEach(function (): void {
    /** @var array{user: User} $seed */
    $seed = $this->seedFixtureUserAndAccount();
    $this->user = $seed['user'];
    $this->actingAs($this->user);
    $this->importer = $this->app->make(RunsImports::class);
});

function importMonthHalves(User $user): void
{
    /** @var RunsImports $importer */
    $importer = test()->importer;

    foreach (['april', 'may'] as $half) {
        $importer->runAndConfirm(
            base_path("Modules/Ingestion/tests/fixtures/paypal/paypal-month-boundary-{$half}.csv"),
            'paypal-csv',
            $user,
            "paypal-month-boundary-{$half}.csv",
        );
    }
}

/**
 * @return list<ImportRowIssue>
 */
function issuesOfLatestRun(): array
{
    /** @var ImportRun $run */
    $run = ImportRun::query()->orderByDesc('id')->firstOrFail();

    return ImportRowIssue::listFromStored($run->row_issues);
}

it('never reports a stranded row as merely unreadable', function (): void {
    importMonthHalves($this->user);

    $reasons = array_map(static fn (ImportRowIssue $i): ?ImportFailureReason => $i->reason, issuesOfLatestRun());

    expect($reasons)->not->toBeEmpty();
    expect($reasons)->not->toContain(ImportFailureReason::RowUnreadable);
});

it('tells the reader which row was stranded and that the other statement holds its parent', function (): void {
    importMonthHalves($this->user);

    $stranded = array_values(array_filter(
        issuesOfLatestRun(),
        static fn (ImportRowIssue $i): bool => $i->reason === ImportFailureReason::RowBelongsToAnotherStatement,
    ));

    expect($stranded)->toHaveCount(2);
    foreach ($stranded as $issue) {
        expect($issue->rowIndex)->not->toBeNull();
        expect($issue->detail)->not->toBeNull();
        expect($issue->detail)->toContain('Algemene valutaomrekening');
        expect($issue->reasonLabel())->not->toBe(ImportFailureReason::RowUnreadable->label());
    }
});

// A row that failed with nothing under it left the reader with "this row could
// not be read" and no way to act on it, whatever the cause.
it('never stores a row issue without a detail', function (): void {
    importMonthHalves($this->user);

    foreach (ImportRun::all() as $run) {
        foreach (ImportRowIssue::listFromStored($run->row_issues) as $issue) {
            if ($issue->reason === null) {
                continue;
            }
            expect($issue->detail)->not->toBeNull("Row {$issue->rowIndex} failed as '{$issue->reason->value}' with no detail.");
        }
    }
});

it('still imports the rows either half of the split can stand up on its own', function (): void {
    importMonthHalves($this->user);

    /** @var list<ImportRun> $runs */
    $runs = ImportRun::query()->orderBy('id')->get()->all();

    expect($runs)->toHaveCount(2);
    expect(Transaction::count())->toBe(4);
});
