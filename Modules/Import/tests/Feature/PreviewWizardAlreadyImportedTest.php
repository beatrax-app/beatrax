<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\ImportRunStatus;

// RunImport short-circuits a SHA256 it has already landed, so re-uploading a
// finished file returns that run and its preview cache is long gone. Reporting
// that as "expired — re-upload the file" sent the reader straight back to the
// upload form, which returns the same run: a loop with no truthful answer.

function alreadyImportedUser(): User
{
    return User::query()->create([
        'username' => 'already-imported@beatrax.local',
        'password' => 'x',
        'period_start_day' => 1,
    ]);
}

function confirmedRunFor(User $user): int
{
    /** @var RunsImports $importer */
    $importer = app(RunsImports::class);

    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $user,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    app(ConfirmsImports::class)($preview->importRunId, $user);

    return $preview->importRunId;
}

it('tells the reader a confirmed file is already imported rather than expired', function (): void {
    $user = alreadyImportedUser();
    $runId = confirmedRunFor($user);

    expect(ImportRun::query()->whereKey($runId)->value('status'))
        ->toBe(ImportRunStatus::Confirmed->value);

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->assertSee('This file has already been imported.')
        ->assertDontSee('The preview has expired', escape: false);
});

// The whole point of the message is the way out of the loop.
it('links a confirmed run at its results page', function (): void {
    $user = alreadyImportedUser();
    $runId = confirmedRunFor($user);

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->assertSee(route('imports.results', ['id' => $runId]), escape: false);
});

// A run that never reached confirmed still reports expiry, so the new branch
// cannot swallow the case the old copy was actually written for.
it('still reports a genuinely expired preview as expired', function (): void {
    $user = alreadyImportedUser();

    /** @var RunsImports $importer */
    $importer = app(RunsImports::class);
    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $user,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    app(PreviewCache::class)->forget($preview->importRunId);

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertSee('The preview has expired', escape: false)
        ->assertDontSee('This file has already been imported.');
});
