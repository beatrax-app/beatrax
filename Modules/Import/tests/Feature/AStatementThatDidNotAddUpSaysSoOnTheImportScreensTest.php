<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\ImportResults;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ledger\Models\StatementSummary;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

// A bank statement states what the account held before its first entry and
// after its last, and the parser has worked out whether the two agree with the
// rows since the self-check landed. Nothing put the answer in front of anyone:
// a file the importer misread reached the ledger with the disagreement recorded
// in a column no screen opened.
function statementDiffOnScreenPreviewRun(User $user): int
{
    /** @var RunsImports $importer */
    $importer = app(RunsImports::class);

    return $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-camt053-sample-1.xml',
        'camt053',
        $user,
        'asn-camt053-sample-1.xml',
    )->importRunId;
}

// The shipped fixture reconciles exactly, so the difference a misread file
// would have carried is written onto its summary here. What the parser puts in
// the column is pinned by the adapters' own tests; what a screen does with it
// is this file's subject.
function statementDiffOnScreenPlant(int $runId, int $differenceMinor, string $currency = 'EUR'): void
{
    /** @var StatementSummary $summary */
    $summary = StatementSummary::query()->where('import_run_id', $runId)->firstOrFail();

    $summary->extras = ['statementDifferenceMinor' => $differenceMinor];
    $summary->closing_balance_currency = $currency;
    $summary->opening_balance_currency = $currency;
    $summary->save();
}

it('says on the preview that the file about to be written does not add up', function (): void {
    $runId = statementDiffOnScreenPreviewRun($this->fixtureUser);
    statementDiffOnScreenPlant($runId, 1200);

    Livewire::test(PreviewWizard::class, ['id' => $runId])
        ->assertSee('This statement does not add up')
        ->assertSee('lands €12.00 below the closing balance it states')
        ->assertSee('Nothing has been written to your ledger yet.');
});

// closing - (opening + entries) is positive where the rows account for less
// movement than the two balances do, and negative where they account for more.
// "off by €12.00" names neither, and the two send a reader to different rows.
it('names the other direction on the preview', function (): void {
    $runId = statementDiffOnScreenPreviewRun($this->fixtureUser);
    statementDiffOnScreenPlant($runId, -1200);

    Livewire::test(PreviewWizard::class, ['id' => $runId])
        ->assertSee('lands €12.00 above the closing balance it states')
        ->assertDontSee('below the closing balance it states');
});

// The key is absent both on a statement that balanced and on one that could
// never be checked, and this fixture is the first of those: a screen that read
// absence as "nothing was checked" would say so over 229 entries that add up.
it('says nothing on the preview about a statement that adds up', function (): void {
    $runId = statementDiffOnScreenPreviewRun($this->fixtureUser);

    expect(StatementSummary::query()->where('import_run_id', $runId)->value('extras'))
        ->not->toHaveKey('statementDifferenceMinor');

    Livewire::test(PreviewWizard::class, ['id' => $runId])
        ->assertDontSee('does not add up');
});

it('still says it on the results screen once the run is confirmed', function (): void {
    $runId = statementDiffOnScreenPreviewRun($this->fixtureUser);
    statementDiffOnScreenPlant($runId, 1200);

    app(ConfirmsImports::class)($runId, $this->fixtureUser);

    Livewire::test(ImportResults::class, ['id' => $runId])
        ->assertSee('lands €12.00 below the closing balance it states')
        ->assertSee('nothing was corrected');
});

it('says nothing on the results screen about a statement that adds up', function (): void {
    $runId = statementDiffOnScreenPreviewRun($this->fixtureUser);

    app(ConfirmsImports::class)($runId, $this->fixtureUser);

    Livewire::test(ImportResults::class, ['id' => $runId])
        ->assertDontSee('does not add up');
});

// A yen has no minor unit, so a difference of 1 200 of them is ¥1,200 and not
// ¥12. Dividing by a hundred on the way to the screen would report a
// hundredth of the gap on every zero-decimal statement.
it('renders the difference of a zero-decimal statement at its own scale', function (): void {
    $runId = statementDiffOnScreenPreviewRun($this->fixtureUser);
    statementDiffOnScreenPlant($runId, 1200, 'JPY');

    Livewire::test(PreviewWizard::class, ['id' => $runId])
        ->assertSee('lands ¥1,200 below the closing balance it states');
});
