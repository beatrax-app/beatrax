<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;

function unreadableFileUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'x',
        'period_start_day' => 1,
    ]);
}

// The pipeline does not throw on a file it cannot read: it returns a
// fileFailureReason and no rows, and the wizard renders that. This is the
// preview the reader's screen is drawn from after such a run.
function previewThatReadNothing(User $user, string $sourceFormat, string $sha): int
{
    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => $sourceFormat,
        'raw_file_path' => 'imports/'.$user->id.'/'.$sha.'.pdf',
        'sha256' => $sha,
        'uploaded_at' => now(),
    ]);

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);
    $cache->put($run->id, new ImportPreviewResult(
        importRunId: $run->id,
        rows: [],
        accountsToName: [],
        fileFailureReason: ImportFailureReason::PdfReaderUnavailable,
    ), []);

    return $run->id;
}

it('does not ask for an ICS card name for a file nothing could be read from', function (): void {
    $user = unreadableFileUser('ics-unreadable');

    $data = Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => previewThatReadNothing($user, 'ics-pdf', str_repeat('a', 64))])
        ->viewData('needsIcsAccountName');

    expect($data)->toBeFalse();
});

it('shows why the file failed instead of the naming prompt', function (): void {
    $user = unreadableFileUser('ics-unreadable-copy');

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => previewThatReadNothing($user, 'ics-pdf', str_repeat('b', 64))])
        ->assertDontSee(__('import::preview.ics.heading'))
        ->assertSee(ImportFailureReason::PdfReaderUnavailable->fileCause());
});

it('creates no account when the name is submitted for a file nothing could be read from', function (): void {
    $user = unreadableFileUser('ics-unreadable-write');
    $runId = previewThatReadNothing($user, 'ics-pdf', str_repeat('c', 64));

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->set('icsAccountName', 'ICS Creditcard')
        ->call('saveIcsAccountName');

    expect(Account::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('does not ask for a PayPal account name for a file nothing could be read from', function (): void {
    $user = unreadableFileUser('paypal-unreadable');

    $data = Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => previewThatReadNothing($user, 'paypal-csv', str_repeat('d', 64))])
        ->viewData('needsPaypalAccountName');

    expect($data)->toBeFalse();
});

it('creates no account when a PayPal name is submitted for a file nothing could be read from', function (): void {
    $user = unreadableFileUser('paypal-unreadable-write');
    $runId = previewThatReadNothing($user, 'paypal-csv', str_repeat('e', 64));

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->set('paypalAccountName', 'PayPal Wallet')
        ->call('savePaypalAccountName');

    expect(Account::query()->where('user_id', $user->id)->count())->toBe(0);
});

// The account a reader named still has to survive a run that read SOME rows and
// stopped: those rows are importable, and the account is what they land in.
it('still asks for a card name when the file failed partway but rows were read', function (): void {
    $user = unreadableFileUser('ics-partial');

    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => 'imports/'.$user->id.'/partial.pdf',
        'sha256' => str_repeat('f', 64),
        'uploaded_at' => now(),
    ]);

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);
    $cache->put($run->id, new ImportPreviewResult(
        importRunId: $run->id,
        rows: [new PreviewRowDto(
            rowIndex: 0,
            status: PreviewRowStatus::NewRow,
            accountId: null,
            bookedAt: '2026-05-10',
            counterpartyName: 'Fixture',
            counterpartyIban: null,
            description: 'fixture-row',
            categoryName: null,
            amountMinor: -1000,
            currency: 'EUR',
            error: null,
        )],
        accountsToName: [],
        fileFailureReason: ImportFailureReason::FileUnreadable,
    ), []);

    $data = Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $run->id])
        ->viewData('needsIcsAccountName');

    expect($data)->toBeTrue();
});
