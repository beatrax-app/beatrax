<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Core\Models\User;
use Modules\Import\Models\KnownCounterpartyIban;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

// The wizard creates accounts and runs imports interleaved, so an ASN row can
// import while no paypal-kind account exists yet: the classifier resolves the
// alias but finds no destination and the row lands as `expense`. The existing
// end-to-end test creates all three accounts up front and misses that race.

it('heals an ASN row left mis-typed by the wizard-order race and closes its pair with the PayPal leg', function (): void {
    $user = User::query()->create([
        'username' => 'wizard-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    // The alias seeder is instantiated directly; its dispatch path is covered
    // elsewhere.
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $user->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);

    $bank = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN Wessel',
        'slug' => 'asn-wessel',
        'kind' => 'bank',
        'iban' => 'NL12ASNB8850776713',
        'default_currency' => 'EUR',
    ]);

    // ASN import while no paypal account exists, so the row lands as `expense`.
    // The post-confirm state is simulated directly.
    $asnRun = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/asn-wizard',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-30 09:00:00'),
        'status' => 'confirmed',
    ]);
    $asnRow = Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $bank->id,
        'type' => 'expense', // ← the mis-typed row the bug produces
        'posted_at' => '2026-04-30',
        'booked_at' => '2026-04-30 12:00:00',
        'value_date' => '2026-04-30',
        'amount_minor' => -1399,
        'currency' => 'EUR',
        'settled_amount_minor' => -1399,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'PayPal Europe S.a.r.l. et Cie S.C.A',
        'counterparty_iban' => 'LU89751000135104200E',
        'counterparty_normalized' => 'PAYPAL EUROPE SARL ET CIE SCA',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $asnRun->id,
        'source_row_index' => 1,
        'fingerprint' => 'asn-wizard-1',
        'fingerprint_version' => 1,
    ]);

    $paypal = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'PayPal',
        'slug' => 'paypal-paypal',
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);

    // The PayPal funding leg is already typed correctly: its mapping reads the
    // row's own `Bankstorting` event type, not a cross-account lookup.
    $paypalRun = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/paypal-wizard',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-30 10:00:00'),
        'status' => 'confirmed',
    ]);
    $paypalRow = Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $paypal->id,
        'type' => 'transfer_in',
        'posted_at' => '2026-04-30',
        'booked_at' => '2026-04-30 00:00:00',
        'value_date' => '2026-04-30',
        'amount_minor' => 1399,
        'currency' => 'EUR',
        'settled_amount_minor' => 1399,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Bankstorting',
        'counterparty_iban' => null,
        'counterparty_normalized' => 'BANKSTORTING',
        'normalization_version' => 1,
        'source_format' => 'paypal-csv',
        'import_run_id' => $paypalRun->id,
        'source_row_index' => 1,
        'fingerprint' => 'paypal-wizard-1',
        'fingerprint_version' => 1,
    ]);

    expect($asnRow->refresh()->type)->toBe('expense');
    expect($asnRow->pair_transaction_id)->toBeNull();
    expect($paypalRow->refresh()->pair_transaction_id)->toBeNull();

    // RetypeByAliasResolver runs first, then the orphan-sweep pairer, then the
    // chain resolvers. dispatchSync runs in-process.
    ResolveChainLinksJob::dispatchSync($user->id);

    expect($asnRow->refresh()->type)->toBe('transfer_out');
    expect($asnRow->pair_transaction_id)->toBe($paypalRow->id);
    expect($paypalRow->refresh()->pair_transaction_id)->toBe($asnRow->id);
});
