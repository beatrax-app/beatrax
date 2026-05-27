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

/*
 * End-to-end coverage for the wizard-order race that left the
 * canonical PayPal-funded-by-ASN cross-account hop stuck as
 * `expense` forever:
 *
 *   1. User created, alias seeder fires → known_counterparty_ibans
 *      populated. (Simulated directly here — the user-install
 *      listener tests cover the dispatch path.)
 *   2. ASN bank Account created.
 *   3. ASN row imported with counterparty_iban = LU...
 *      AT THIS MOMENT the PayPal account does NOT yet exist, so the
 *      preview-time classifier resolves the alias but finds no
 *      destination account, and the row lands as `expense`.
 *   4. PayPal Account created (the second wizard step).
 *   5. PayPal-side `transfer_in` row imported (already correctly
 *      classified by the funding-leg mapping).
 *   6. ResolveChainLinksJob::dispatchSync(userId) fires.
 *   7. Expected post-job state:
 *        - ASN row retyped to `transfer_out` (RetypeByAliasResolver)
 *        - ASN ↔ PayPal pair_transaction_id closed (orphan sweep)
 *
 * Captures the regression the existing CrossAccountHopClassificationEndToEndTest
 * misses: that test creates all 3 accounts UP FRONT, so the
 * preview-time classifier always sees the destination account and
 * the row never falls through to the expense default. The race only
 * manifests when accounts and imports interleave — exactly the
 * shape the onboarding wizard produces.
 */

it('heals an ASN row left mis-typed by the wizard-order race and closes its pair with the PayPal leg', function (): void {
    $user = User::query()->create([
        'username' => 'wizard-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    // Step 1 — UserInstalled listener seeds the canonical PayPal alias.
    // (Tested directly elsewhere; instantiated here so this test
    // exercises the chain job, not the seeder.)
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $user->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);

    // Step 2 — ASN bank account materialises FIRST.
    $bank = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN Wessel',
        'slug' => 'asn-wessel',
        'kind' => 'bank',
        'iban' => 'NL12ASNB8850776713',
        'default_currency' => 'EUR',
    ]);

    // Step 3 — ASN import. Because no `paypal`-kind account exists
    // yet, the classifier's alias bridge resolves the alias row but
    // not its destination account; the row lands as `expense`. We
    // simulate the post-confirm state directly.
    $asnRun = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-camt053',
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
        'source_format' => 'asn-camt053',
        'import_run_id' => $asnRun->id,
        'source_row_index' => 1,
        'fingerprint' => 'asn-wizard-1',
        'fingerprint_version' => 1,
    ]);

    // Step 4 — PayPal account materialises (the second wizard step).
    $paypal = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'PayPal',
        'slug' => 'paypal-paypal',
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);

    // Step 5 — PayPal-side `transfer_in` row (the funding leg).
    // Already correctly typed because the PayPal funding-leg mapping
    // runs against the row's own `Bankstorting` event type, not
    // against any cross-account lookup.
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

    // Pre-condition sanity: the bug state is what the test is healing.
    expect($asnRow->refresh()->type)->toBe('expense');
    expect($asnRow->pair_transaction_id)->toBeNull();
    expect($paypalRow->refresh()->pair_transaction_id)->toBeNull();

    // Step 6 — Chain resolution job fires. RetypeByAliasResolver runs
    // first, then the orphan-sweep pairer, then the two existing chain
    // resolvers. dispatchSync runs in-process; no queue worker needed.
    ResolveChainLinksJob::dispatchSync($user->id);

    // Step 7 — Post-job invariants.
    expect($asnRow->refresh()->type)->toBe('transfer_out');
    expect($asnRow->pair_transaction_id)->toBe($paypalRow->id);
    expect($paypalRow->refresh()->pair_transaction_id)->toBe($asnRow->id);
});
