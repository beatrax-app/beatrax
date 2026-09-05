<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Import\Public\Exceptions\ImportNotConfirmableException;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\ImportRunStatus;

// The fixture's third <Ntry> carries neither BookgDt nor ValDt, which
// Camt053Adapter raises on from inside the generator's yield loop -- so entries
// one and two are already in the preview when the read stops. Two entries of
// three is the shape of 499 entries of 1200.
function previewOfTheRunThatStoppedShort(User $user): ImportPreviewResult
{
    /** @var RunsImports $importer */
    $importer = app(RunsImports::class);

    return $importer->runFromUpload(
        base_path('tests/fixtures/asn-camt053-stops-at-the-third-entry.xml'),
        'camt053',
        $user,
        'february.xml',
    );
}

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('reads the entries before the failure and records where the read stopped', function (): void {
    $preview = previewOfTheRunThatStoppedShort($this->fixtureUser);

    expect($preview->fileFailureReason)->toBe(ImportFailureReason::FileStoppedShort)
        ->and($preview->totalRows())->toBe(2)
        ->and($preview->errorRows())->toBe(0)
        ->and($preview->importableRows())->toBe(2)
        ->and($preview->fileFailureRowIndex)->toBe(2);
});

it('refuses to confirm a run whose file stopped being read, however much it read first', function (): void {
    $preview = previewOfTheRunThatStoppedShort($this->fixtureUser);

    /** @var ConfirmsImports $confirmer */
    $confirmer = $this->app->make(ConfirmsImports::class);

    expect(fn () => ($confirmer)($preview->importRunId, $this->fixtureUser))
        ->toThrow(
            ImportNotConfirmableException::class,
            'cannot be confirmed: reading it stopped before the end, so whatever it holds past that point was never seen.',
        );
});

it('writes nothing and leaves the run unconfirmed when the confirm is refused', function (): void {
    $preview = previewOfTheRunThatStoppedShort($this->fixtureUser);

    /** @var ConfirmsImports $confirmer */
    $confirmer = $this->app->make(ConfirmsImports::class);

    try {
        ($confirmer)($preview->importRunId, $this->fixtureUser);
    } catch (ImportNotConfirmableException) {
        // The refusal itself is the test above. Here it is the setup for what
        // has to be true after it, and rethrowing would restate that
        // assertion inside a test about the database.
    }

    /** @var ImportRun $run */
    $run = ImportRun::query()->findOrFail($preview->importRunId);

    expect($run->status)->toBe(ImportRunStatus::Previewed->value)
        ->and($run->confirmed_at)->toBeNull()
        ->and(Transaction::query()->where('import_run_id', $preview->importRunId)->count())->toBe(0);
});

it('disables the wizard button and tells the reader what to do instead', function (): void {
    $preview = previewOfTheRunThatStoppedShort($this->fixtureUser);

    $wizard = Livewire::actingAs($this->fixtureUser)
        ->test(PreviewWizard::class, ['id' => $preview->importRunId]);

    expect($wizard->viewData('confirmRefused'))->toBeTrue();

    $wizard->assertSee(Lang::get('import::preview.failed.truncated'))
        ->assertSee(Lang::get('import::preview.failed.truncated_action'));
});

it('does not confirm when the wizard button is pressed anyway', function (): void {
    $preview = previewOfTheRunThatStoppedShort($this->fixtureUser);

    Livewire::actingAs($this->fixtureUser)
        ->test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->call('confirm')
        ->assertNoRedirect();

    /** @var ImportRun $run */
    $run = ImportRun::query()->findOrFail($preview->importRunId);

    expect($run->status)->toBe(ImportRunStatus::Previewed->value)
        ->and(Transaction::query()->count())->toBe(0);
});

// The control group: the same format, read to its end, still confirms and
// lands. Without it every assertion above would pass against a wizard that had
// simply stopped importing anything.
it('still confirms a CAMT.053 file that reads all the way through', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);

    $result = $importer->runAndConfirm(
        base_path('tests/fixtures/asn-camt053-sample-1.xml'),
        'camt053',
        $this->fixtureUser,
    );

    expect($result->inserted)->toBeGreaterThan(0)
        ->and(Transaction::query()->where('import_run_id', $result->importRunId)->count())->toBe($result->inserted);
});
