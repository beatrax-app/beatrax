<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\ImportRun;
use Tests\Helpers\UploadIsolation;

uses(RefreshDatabase::class);

// import_runs.raw_file_path is a synced column, so the value this device reads
// back may have been written by a peer, against that device's filesystem. It is
// also an audit string that carries a sentinel for every run nobody uploaded.
const PATH_NEVER_STAGED_IBAN = 'NL57ASNB0123456789';

beforeEach(function (): void {
    UploadIsolation::isolate();
    $this->freezeClockOnTheStatementFixtureWindow();

    $this->reader = User::query()->create([
        'username' => 'never-staged-reader',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->reader);
});

function aPathNeverStagedRun(User $reader): ImportRun
{
    /** @var RunsImports $importer */
    $importer = app(RunsImports::class);

    $preview = $importer->runFromUpload(
        base_path('tests/fixtures/asn-sample-1.csv'),
        'asn-csv',
        $reader,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    expect($preview->accountsToName)->not->toBe([], 'the fixture named every account, so nameAccount() is unreachable');

    /** @var ImportRun $run */
    $run = ImportRun::query()->findOrFail($preview->importRunId);

    return $run;
}

/** @return list<string> the basenames this reader's staging directory holds */
function aPathNeverStagedStagedFiles(User $reader): array
{
    $files = Storage::disk('local')->files('imports/'.$reader->id);
    sort($files);

    return array_values(array_map(static fn (string $path): string => basename($path), $files));
}

it('does not open a file the run names outside this device staging directory', function (): void {
    $run = aPathNeverStagedRun($this->reader);
    $before = aPathNeverStagedStagedFiles($this->reader);

    // What a peer's row looks like once it has replayed here: an absolute path
    // that resolves, and names a file this device never staged.
    $elsewhere = base_path('composer.json');
    $run->forceFill(['raw_file_path' => $elsewhere])->save();

    Livewire::test(PreviewWizard::class, ['id' => $run->id])
        ->call('nameAccount', PATH_NEVER_STAGED_IBAN, 'ASN current')
        ->assertHasNoErrors();

    expect(aPathNeverStagedStagedFiles($this->reader))->toBe(
        $before,
        'the run named a file outside imports/, and this device hashed it, copied it into the reader\'s own '
        .'staging directory and parsed it: '.$elsewhere,
    );
});

// The control. Every assertion above is satisfied by a re-read that no longer
// happens at all, which would leave the reader naming an account and watching
// the row it belongs to stay unnamed.
it('still re-reads the run this device staged itself', function (): void {
    $run = aPathNeverStagedRun($this->reader);

    Livewire::test(PreviewWizard::class, ['id' => $run->id])
        ->call('nameAccount', PATH_NEVER_STAGED_IBAN, 'ASN current')
        ->assertHasNoErrors();

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);
    $preview = $cache->getPreview($run->id);

    expect($preview)->not->toBeNull()
        ->and($preview?->accountsToName)->toBe([], 'the named account was still listed as one to name');
});

it('does not re-read a run whose stored path is a sentinel rather than a file', function (): void {
    $run = aPathNeverStagedRun($this->reader);
    $before = aPathNeverStagedStagedFiles($this->reader);

    // Four writers put a non-path here: `open-banking://`, `demo://`,
    // `migration`, and the receipts handoff sentinel.
    $run->forceFill(['raw_file_path' => 'migration'])->save();

    Livewire::test(PreviewWizard::class, ['id' => $run->id])
        ->call('nameAccount', PATH_NEVER_STAGED_IBAN, 'ASN current')
        ->assertHasNoErrors();

    expect(aPathNeverStagedStagedFiles($this->reader))->toBe($before);
});
