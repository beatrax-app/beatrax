<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Actions\EnsurePaypalAccountAction;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\UnknownIban;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;

// The PayPal export carries no account number, so the adapter stands one in.
// The bespoke prompt claims that literal; the generic namer holds whatever it
// is given to an IBAN's shape and can only refuse it.
function paypalPreviewFor(User $user): int
{
    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => SourceFormat::PaypalCsv->value,
        'raw_file_path' => 'imports/'.$user->id.'/'.str_repeat('a', 64).'.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => now(),
    ]);

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);
    $cache->put($run->id, new ImportPreviewResult(
        importRunId: $run->id,
        rows: [],
        accountsToName: [new UnknownIban(EnsurePaypalAccountAction::PAYPAL_OWN_IBAN, null)],
    ), []);

    return $run->id;
}

function paypalImportUser(string $username): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => $username,
        'password' => 'x',
        'period_start_day' => 1,
    ]);

    return $user;
}

it('still offers the PayPal naming prompt when a wallet exists under another identifier', function (): void {
    $user = paypalImportUser('paypal-other-identifier');

    // As a migration import or an older naming path leaves it.
    Account::query()->create([
        'user_id' => $user->id,
        'name' => 'PayPal',
        'slug' => 'paypal-legacy',
        'kind' => AccountKind::Paypal->value,
        'iban' => 'PAYPAL-DEMO-1',
        'default_currency' => 'EUR',
    ]);

    $runId = paypalPreviewFor($user);

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->assertSet('paypalAccountName', '')
        ->assertSee(__('import::preview.paypal.heading'))
        ->assertDontSee(__('import::preview.unknown_iban_prefix'));
});
