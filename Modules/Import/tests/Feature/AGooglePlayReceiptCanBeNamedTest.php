<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Actions\EnsureGooglePlayAccountAction;
use Modules\Import\Public\Actions\EnsurePaypalAccountAction;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Dto\UnknownIban;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;

// GooglePlayReceiptMatcher emits the synthetic own-IBAN GOOGLE-PLAY and nothing
// in the app could mint an account for it: the generic namer rejects the literal
// as a malformed IBAN, so the receipt parsed, the audit row said processed, and
// the ledger stayed empty on every path.

function googlePlayNamingUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'x',
        'period_start_day' => 1,
    ]);
}

// A real file on disk, because naming the account re-runs the import over it.
function googlePlayEmlOnDisk(): string
{
    $path = tempnam(sys_get_temp_dir(), 'gp-receipt').'.eml';
    file_put_contents($path, implode("\r\n", [
        'From: googleplay-noreply@google.com',
        'To: reader@example.test',
        'Subject: Your Google Play Order Receipt',
        'Date: Sun, 17 May 2026 09:30:00 +0000',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Order number: GPA.1234-5678-9012-34567',
        'Item: Headspace subscription',
        'Total: $12.99 USD',
        '',
    ]));

    return $path;
}

function googlePlayEmlRunWithUnknownIban(User $user, string $sha, string $unknownIban): int
{
    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'eml',
        'raw_file_path' => googlePlayEmlOnDisk(),
        'sha256' => $sha,
        'uploaded_at' => now(),
    ]);

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);
    $cache->put(
        $run->id,
        new ImportPreviewResult(
            importRunId: $run->id,
            rows: [new PreviewRowDto(
                rowIndex: 0,
                status: PreviewRowStatus::Error,
                accountId: null,
                postedAt: '2026-05-17',
                counterpartyName: 'Google Play',
                counterpartyIban: null,
                description: 'Google Play',
                amountMinor: -1299,
                currency: 'USD',
                error: null,
            )],
            accountsToName: [new UnknownIban($unknownIban, 'Google Play')],
        ),
        canonical: [],
    );

    return $run->id;
}

it('asks for a Google Play account name when the preview could not resolve the synthetic IBAN', function (): void {
    $user = googlePlayNamingUser('gp-unnamed@beatrax.local');
    $runId = googlePlayEmlRunWithUnknownIban($user, str_repeat('a', 64), EnsureGooglePlayAccountAction::GOOGLE_PLAY_OWN_IBAN);

    $data = Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->viewData('needsGooglePlayAccountName');

    expect($data)->toBeTrue();
});

// Keyed on the preview, not on the run's format: .eml and .mbox are transports
// that carry PayPal and ICS receipts too, so a run that never produced the
// Google Play literal must not be asked to name a Google Play account.
it('stays silent on a receipt run whose unknown IBAN belongs to another provider', function (): void {
    $user = googlePlayNamingUser('gp-other-provider@beatrax.local');
    $runId = googlePlayEmlRunWithUnknownIban($user, str_repeat('b', 64), EnsurePaypalAccountAction::PAYPAL_OWN_IBAN);

    $data = Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->viewData('needsGooglePlayAccountName');

    expect($data)->toBeFalse();
});

it('stops asking once an account carries the synthetic Google Play IBAN', function (): void {
    $user = googlePlayNamingUser('gp-claimed@beatrax.local');

    Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Google Play',
        'slug' => 'gp-claimed',
        'kind' => AccountKind::GooglePlay->value,
        'iban' => EnsureGooglePlayAccountAction::GOOGLE_PLAY_OWN_IBAN,
        'default_currency' => 'EUR',
    ]);

    $runId = googlePlayEmlRunWithUnknownIban($user, str_repeat('c', 64), EnsureGooglePlayAccountAction::GOOGLE_PLAY_OWN_IBAN);

    $data = Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->viewData('needsGooglePlayAccountName');

    expect($data)->toBeFalse();
});

it('mints the account under the Google Play kind when the reader names it', function (): void {
    $user = googlePlayNamingUser('gp-names-it@beatrax.local');
    $runId = googlePlayEmlRunWithUnknownIban($user, str_repeat('d', 64), EnsureGooglePlayAccountAction::GOOGLE_PLAY_OWN_IBAN);

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->set('googlePlayAccountName', 'Play Store')
        ->call('saveGooglePlayAccountName')
        ->assertHasNoErrors();

    $account = Account::query()
        ->where('user_id', $user->id)
        ->where('iban', EnsureGooglePlayAccountAction::GOOGLE_PLAY_OWN_IBAN)
        ->firstOrFail();

    expect($account->name)->toBe('Play Store');
    expect($account->kind)->toBe(AccountKind::GooglePlay->value);
});

it('refuses to confirm the run while the Google Play account is still unnamed', function (): void {
    $user = googlePlayNamingUser('gp-blocks-confirm@beatrax.local');
    $runId = googlePlayEmlRunWithUnknownIban($user, str_repeat('e', 64), EnsureGooglePlayAccountAction::GOOGLE_PLAY_OWN_IBAN);

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->call('confirm')
        ->assertNoRedirect();

    expect(ImportRun::query()->where('id', $runId)->value('status'))->not->toBe('confirmed');
});
