<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\StatementSummary;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

// The target this screen prefills is the closing balance of the newest
// confirmed statement, and the reader drives the difference to zero against
// it. Where that statement's own rows disagreed with its own balances, the
// figure in the box is the one the file stated and the rows never reached —
// offered with nothing beside it saying so.
function reconcileStatementDiffConfirmedRun(User $user): int
{
    /** @var RunsImports $importer */
    $importer = app(RunsImports::class);

    $runId = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-camt053-sample-1.xml',
        'camt053',
        $user,
        'asn-camt053-sample-1.xml',
    )->importRunId;

    app(ConfirmsImports::class)($runId, $user);

    return $runId;
}

function reconcileStatementDiffPlant(int $runId, int $differenceMinor): void
{
    /** @var StatementSummary $summary */
    $summary = StatementSummary::query()->where('import_run_id', $runId)->firstOrFail();

    $summary->extras = ['statementDifferenceMinor' => $differenceMinor];
    $summary->save();
}

it('says the prefilled target came from a statement that did not add up', function (): void {
    $runId = reconcileStatementDiffConfirmedRun($this->fixtureUser);
    reconcileStatementDiffPlant($runId, 1200);

    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();

    Livewire::test(ReconcilePage::class, ['accountId' => $account->id])
        ->assertSee('This statement does not add up')
        ->assertSee('lands €12.00 below the closing balance it states')
        ->assertSee('was pre-filled from that statement');
});

// The caveat is shown beside the figure, never instead of it: a flagged
// starting point the reader can see beats an empty box, and removing the
// prefill would take the flag's own subject off the screen with it.
it('still prefills the target it has just flagged', function (): void {
    $runId = reconcileStatementDiffConfirmedRun($this->fixtureUser);
    reconcileStatementDiffPlant($runId, 1200);

    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();

    Livewire::test(ReconcilePage::class, ['accountId' => $account->id])
        ->assertNotSet('statementBalance', '')
        ->assertSee('This statement does not add up');
});

it('names the other direction beside the prefilled target', function (): void {
    $runId = reconcileStatementDiffConfirmedRun($this->fixtureUser);
    reconcileStatementDiffPlant($runId, -1200);

    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();

    Livewire::test(ReconcilePage::class, ['accountId' => $account->id])
        ->assertSee('lands €12.00 above the closing balance it states')
        ->assertDontSee('below the closing balance it states');
});

it('says nothing beside a target taken from a statement that adds up', function (): void {
    reconcileStatementDiffConfirmedRun($this->fixtureUser);

    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();

    Livewire::test(ReconcilePage::class, ['accountId' => $account->id])
        ->assertNotSet('statementBalance', '')
        ->assertDontSee('does not add up');
});
