<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Dto\UnknownIban;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Enums\SyntheticIban;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;

// A PayPal or ICS receipt dropped as .eml carries that provider's synthetic
// own-IBAN, but the run's source_format names the transport the receipt arrived
// on. Both bespoke prompts key on source_format, so neither fired: the stand-in
// fell through to the generic namer, which holds an identifier to 15..34
// uppercase alphanumerics and can only refuse PAYPAL and ICS-CARD. The reader
// reached a naming prompt whose Save button could not mint the account.

function receiptDropUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'x',
        'period_start_day' => 1,
    ]);
}

// A real file, because naming the account re-reads the source over it.
function paypalReceiptOnDisk(): string
{
    $path = tempnam(sys_get_temp_dir(), 'paypal-receipt').'.eml';
    file_put_contents($path, implode("\r\n", [
        'From: service@paypal.com',
        'To: reader@example.test',
        'Subject: Je ontvangstbewijs van Netflix BV',
        'Date: Sun, 17 May 2026 09:42:13 +0200',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Aan: Netflix BV',
        'Bedrag: EUR 12,99',
        'Transaction ID: PAYPALTXN17052026',
        '',
    ]));

    return $path;
}

function icsReceiptOnDisk(): string
{
    $path = tempnam(sys_get_temp_dir(), 'ics-receipt').'.eml';
    file_put_contents($path, implode("\r\n", [
        'From: noreply@icscards.nl',
        'To: reader@example.test',
        'Subject: Uw maandoverzicht staat klaar',
        'Date: Mon, 18 May 2026 07:15:00 +0200',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Uw ICS-maandoverzicht is beschikbaar.',
        'Bedrag: EUR 45,00',
        '',
    ]));

    return $path;
}

function receiptDropRun(User $user, string $sha, string $unknownIban, string $path): int
{
    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => SourceFormat::Eml->value,
        'raw_file_path' => $path,
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
                counterpartyName: 'Netflix BV',
                counterpartyIban: null,
                description: 'Netflix BV',
                amountMinor: -1299,
                currency: 'EUR',
                error: null,
            )],
            accountsToName: [new UnknownIban($unknownIban, null, 'EUR')],
        ),
        canonical: [],
    );

    return $run->id;
}

it('names the wallet from the prompt an email drop actually shows', function (): void {
    $user = receiptDropUser('receipt-drop-paypal@beatrax.local');
    $runId = receiptDropRun($user, str_repeat('1', 64), SyntheticIban::Paypal->value, paypalReceiptOnDisk());

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->assertSee(__('import::preview.paypal.heading'))
        ->assertDontSee(__('import::preview.unknown_account_prefix'))
        ->set('paypalAccountName', 'Household wallet')
        ->call('savePaypalAccountName')
        ->assertHasNoErrors();

    $account = Account::query()
        ->where('user_id', $user->id)
        ->where('iban', SyntheticIban::Paypal->value)
        ->firstOrFail();

    expect($account->name)->toBe('Household wallet');
    expect($account->kind)->toBe(AccountKind::Paypal->value);
    expect($account->default_currency)->toBe('EUR');
});

it('names the card from the prompt an email drop actually shows', function (): void {
    $user = receiptDropUser('receipt-drop-ics@beatrax.local');
    $runId = receiptDropRun($user, str_repeat('2', 64), SyntheticIban::IcsCard->value, icsReceiptOnDisk());

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->assertSee(__('import::preview.ics.heading'))
        ->assertDontSee(__('import::preview.unknown_account_prefix'))
        ->set('icsAccountName', 'Blue card')
        ->call('saveIcsAccountName')
        ->assertHasNoErrors();

    $account = Account::query()
        ->where('user_id', $user->id)
        ->where('iban', SyntheticIban::IcsCard->value)
        ->firstOrFail();

    expect($account->name)->toBe('Blue card');
    expect($account->kind)->toBe(AccountKind::IcsCard->value);
    expect($account->default_currency)->toBe('EUR');
});

// The same .eml and .mbox transports carry all three providers, so a drop that
// produced one sentinel must not be asked to name the other two.
it('stays silent about the wallet and the card on a drop that named neither', function (): void {
    $user = receiptDropUser('receipt-drop-other-provider@beatrax.local');
    $runId = receiptDropRun($user, str_repeat('3', 64), SyntheticIban::GooglePlay->value, paypalReceiptOnDisk());

    $wizard = Livewire::actingAs($user)->test(PreviewWizard::class, ['id' => $runId]);

    expect($wizard->viewData('needsPaypalAccountName'))->toBeFalse();
    expect($wizard->viewData('needsIcsAccountName'))->toBeFalse();
});

// The wallet the drop needs is the one the reader already has, so a second drop
// asks for nothing -- the prompt closes on the literal, not on the format.
it('stops asking on a later email drop once the wallet is named', function (): void {
    $user = receiptDropUser('receipt-drop-paypal-claimed@beatrax.local');

    Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Household wallet',
        'slug' => 'receipt-drop-paypal-claimed',
        'kind' => AccountKind::Paypal->value,
        'iban' => SyntheticIban::Paypal->value,
        'default_currency' => 'EUR',
    ]);

    $runId = receiptDropRun($user, str_repeat('4', 64), SyntheticIban::Paypal->value, paypalReceiptOnDisk());

    $data = Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->viewData('needsPaypalAccountName');

    expect($data)->toBeFalse();
});

it('refuses to confirm an email drop while the wallet is still unnamed', function (): void {
    $user = receiptDropUser('receipt-drop-blocks-confirm@beatrax.local');
    $runId = receiptDropRun($user, str_repeat('5', 64), SyntheticIban::Paypal->value, paypalReceiptOnDisk());

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->call('confirm')
        ->assertNoRedirect();

    expect(ImportRun::query()->where('id', $runId)->value('status'))->not->toBe('confirmed');
});
