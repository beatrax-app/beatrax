<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Tests\Helpers\UploadIsolation;

// The first Google Play receipt a reader ever imports has no account to land
// in yet, so every row it produced is held back as unknown-account and the
// preview counts nothing as importable. The screen read that as "nothing here
// became a transaction, so nothing was added to your ledger" and printed it
// directly above the capture saying "Read as a payment -- confirm this import
// to add it to your ledger", and above the form asking for the very name that
// was holding the row. Naming the account made the sentence disappear and the
// row appear; confirming then imported it. This is the first-run path of every
// receipt provider, and it is never seen on the second import.

beforeEach(function (): void {
    UploadIsolation::isolate();

    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureUser = $seeded['user'];
    $this->actingAs($this->fixtureUser);
});

function dropReceiptEmail(string $path): int
{
    $bytes = (string) file_get_contents($path);

    Livewire::test(UploadWizard::class)
        ->set('importType', 'email')
        ->set('sourceFormat', 'eml')
        ->set('file', UploadedFile::fake()->createWithContent(basename($path), $bytes))
        ->call('submit')
        ->assertHasNoErrors();

    return (int) ImportRun::query()->latest('id')->value('id');
}

function googlePlayReceiptFixture(): string
{
    return base_path('Modules/Receipts/tests/fixtures/googleplay/current-receipt.eml');
}

it('does not say nothing became a transaction while it is asking for the account name', function (): void {
    $runId = dropReceiptEmail(googlePlayReceiptFixture());

    $wizard = Livewire::test(PreviewWizard::class, ['id' => $runId]);

    expect($wizard->viewData('needsGooglePlayAccountName'))->toBeTrue()
        ->and($wizard->viewData('importableRowCount'))->toBe(0);

    $wizard
        ->assertSee(__('import::preview.google_play.heading'))
        ->assertSee(__('import::preview.receipts.state.read'))
        ->assertDontSee(__('import::preview.receipts.none_imported'));
});

// What the receipt was worth all along, through the screen the reader used.
it('imports the transaction it was holding once the account is named', function (): void {
    $runId = dropReceiptEmail(googlePlayReceiptFixture());

    Livewire::test(PreviewWizard::class, ['id' => $runId])
        ->set('googlePlayAccountName', 'Play Store')
        ->call('saveGooglePlayAccountName')
        ->assertHasNoErrors()
        ->assertDontSee(__('import::preview.receipts.none_imported'))
        ->call('confirm')
        ->assertRedirect(route('imports.results', ['id' => $runId]));

    expect(Transaction::query()->where('user_id', $this->fixtureUser->id)->count())->toBe(1);
});

// The sentence is still the right one where it is true: a message from a
// sender no matcher reads asks for no name and never produces a row.
it('still says nothing became a transaction when no name is pending', function (): void {
    $runId = dropReceiptEmail(base_path('Modules/EmailScan/tests/fixtures/eml/ics/sample-statement-notice.eml'));

    $wizard = Livewire::test(PreviewWizard::class, ['id' => $runId]);

    expect($wizard->viewData('importableRowCount'))->toBe(0);

    $wizard
        ->assertSee(__('import::preview.receipts.heading'))
        ->assertSee(__('import::preview.receipts.none_imported'));
});

// The sibling branch. A bank CSV whose rows are all held back by the same
// unnamed account reaches the naming form with its rows drawn below it, and
// each row carries its own reason. No copy there speaks for the whole file, so
// there is nothing to contradict.
it('says nothing about an empty ledger to a bank CSV waiting for the same name', function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    Account::query()->where('iban', 'NL57ASNB0123456789')->delete();

    $preview = app(RunsImports::class)->runFromUpload(
        base_path('tests/fixtures/asn-sample-1.csv'),
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    $wizard = Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId]);

    expect($wizard->viewData('importableRowCount'))->toBe(0);

    $wizard
        ->assertSee(__('import::preview.unknown_iban_prefix'))
        ->assertDontSee(__('import::preview.receipts.none_imported'))
        ->assertDontSee(__('import::preview.failed.heading'))
        ->assertDontSee(__('import::preview.failed.no_rows'))
        ->assertDontSee(__('import::preview.failed.nothing_read'))
        ->assertDontSee(__('import::preview.failed.every_row'));
});
