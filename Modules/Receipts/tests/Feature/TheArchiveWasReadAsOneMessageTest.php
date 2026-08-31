<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureUser = $seeded['user'];
    $this->actingAs($this->fixtureUser);
});

it('files every message of an archive dropped while the format is at its email default', function (): void {
    $file = UploadedFile::fake()->createWithContent('paypal-mixed.mbox', (string) file_get_contents(__DIR__.'/../fixtures/mbox/paypal-mixed.mbox'));

    $wizard = Livewire::test(UploadWizard::class)
        ->set('importType', 'email')
        ->set('file', $file)
        ->assertSet('sourceFormat', SourceFormat::Mbox->value)
        ->assertSee(Lang::get('import::upload.format_from_file', [
            'format' => Lang::get('import::upload.formats.mailbox_archive'),
        ]));

    $wizard->call('submit')->assertHasNoErrors();

    $importRunId = ImportRun::query()->latest('id')->value('id');
    expect($importRunId)->not->toBeNull();
    ($this->app->make(ConfirmsImports::class))($importRunId, $this->fixtureUser);

    expect(ImportRun::query()->find($importRunId)?->source_format)->toBe(SourceFormat::Mbox->value);

    $rows = DB::table('file_imports')->where('user_id', $this->fixtureUser->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(3);
    expect($rows->pluck('status')->all())->toBe(['parsed', 'unmatched', 'skipped']);
    expect(Transaction::query()->where('user_id', $this->fixtureUser->id)->count())->toBe(1);
});

it('refuses an archive declared as a single message instead of filing only its first', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'archive-as-message-');
    file_put_contents($path, (string) file_get_contents(__DIR__.'/../fixtures/mbox/paypal-mixed.mbox'));

    $preview = $this->app->make(RunsImports::class)->runFromUpload(
        $path,
        SourceFormat::Eml->value,
        $this->fixtureUser,
        'paypal-mixed.mbox',
    );

    @unlink($path);

    expect($preview->fileFailureReason)->toBe(ImportFailureReason::FileUnreadable);
    expect($preview->fileFailureDetail)->toBe(Lang::get('import::preview.errors.email_file_is_an_archive'));
    expect($preview->capturedAReceipt())->toBeFalse();
    expect(DB::table('file_imports')->where('user_id', $this->fixtureUser->id)->count())->toBe(0);
});

it('refuses a single message declared as an archive instead of filing nothing at all', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'message-as-archive-');
    file_put_contents($path, (string) file_get_contents(__DIR__.'/../fixtures/paypal/current-receipt.eml'));

    $preview = $this->app->make(RunsImports::class)->runFromUpload(
        $path,
        SourceFormat::Mbox->value,
        $this->fixtureUser,
        'current-receipt.eml',
    );

    @unlink($path);

    expect($preview->fileFailureReason)->toBe(ImportFailureReason::FileUnreadable);
    expect($preview->fileFailureDetail)->toBe(Lang::get('import::preview.errors.archive_holds_one_message'));
    expect(DB::table('file_imports')->where('user_id', $this->fixtureUser->id)->count())->toBe(0);
});

it('refuses a file that is no kind of email rather than filing it as a message', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'csv-as-message-');
    file_put_contents($path, "Datum,Naam,Bedrag\n17-05-2026,Netflix,12.99\n");

    $preview = $this->app->make(RunsImports::class)->runFromUpload(
        $path,
        SourceFormat::Eml->value,
        $this->fixtureUser,
        'statement.csv',
    );

    @unlink($path);

    expect($preview->fileFailureReason)->toBe(ImportFailureReason::FileUnreadable);
    expect($preview->fileFailureDetail)->toBe(Lang::get('import::preview.errors.not_an_email_file'));
    expect(DB::table('file_imports')->where('user_id', $this->fixtureUser->id)->count())->toBe(0);
});

it('tells the reader what the file is when they put the format back to a single message', function (): void {
    $file = UploadedFile::fake()->createWithContent('paypal-mixed.mbox', (string) file_get_contents(__DIR__.'/../fixtures/mbox/paypal-mixed.mbox'));

    Livewire::test(UploadWizard::class)
        ->set('importType', 'email')
        ->set('file', $file)
        ->set('sourceFormat', SourceFormat::Eml->value)
        ->assertDontSee(Lang::get('import::upload.format_from_file', [
            'format' => Lang::get('import::upload.formats.mailbox_archive'),
        ]))
        ->call('submit')
        ->assertHasNoErrors();

    $importRunId = ImportRun::query()->latest('id')->value('id');
    expect($importRunId)->not->toBeNull();

    Livewire::test(PreviewWizard::class, ['id' => $importRunId])
        ->assertSee(Lang::get('import::preview.errors.email_file_is_an_archive'))
        ->assertDontSee(Lang::get('import::preview.receipts.saved'));

    expect(DB::table('file_imports')->where('user_id', $this->fixtureUser->id)->count())->toBe(0);
    expect(Transaction::query()->where('user_id', $this->fixtureUser->id)->count())->toBe(0);
});
