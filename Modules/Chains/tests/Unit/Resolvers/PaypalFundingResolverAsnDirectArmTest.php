<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Modules\Import\Models\KnownCounterpartyIban;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, bank: Account, paypal: Account, run: ImportRun}
 */
function asnDirectFixture(string $username): array
{
    static $counter = 0;
    $counter++;
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $bank = Account::query()->create([
        'user_id' => $user->id,
        'name' => sprintf('ASN-%d', $counter),
        'slug' => sprintf('asn-direct-%d', $counter),
        'kind' => 'bank',
        'iban' => sprintf('NL12ASNB%010d', $counter),
        'default_currency' => 'EUR',
    ]);
    $paypal = Account::query()->create([
        'user_id' => $user->id,
        'name' => sprintf('PayPal-%d', $counter),
        'slug' => sprintf('paypal-direct-%d', $counter),
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $user->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => sprintf('/tmp/asn-direct-%d', $counter),
        'sha256' => str_pad((string) $counter, 64, 'p', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    return compact('user', 'bank', 'paypal', 'run');
}

// The canonical shape this arm handles: no Bankstorting row in raw_payload.
function paypalExpenseRow(
    User $user,
    Account $paypal,
    int $importRunId,
    int $settledMinor,
    string $bookedAt = '2026-04-30 12:00:00',
    string $merchant = 'Google Cloud EMEA Limited',
): Transaction {
    static $rowIndex = 0;
    $rowIndex++;

    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $paypal->id,
        'type' => 'expense',
        'posted_at' => substr($bookedAt, 0, 10),
        'booked_at' => $bookedAt,
        'value_date' => substr($bookedAt, 0, 10),
        'amount_minor' => -$settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => -$settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => $merchant,
        'counterparty_iban' => null,
        'counterparty_normalized' => strtoupper($merchant),
        'normalization_version' => 3,
        'source_format' => 'paypal-csv',
        'import_run_id' => $importRunId,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad('pyp'.$rowIndex, 64, 'p', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

function asnTransferOutToPaypal(
    User $user,
    Account $bank,
    int $importRunId,
    int $settledMinor,
    string $bookedAt = '2026-04-30 12:00:00',
): Transaction {
    static $rowIndex = 0;
    $rowIndex++;

    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $bank->id,
        'type' => 'transfer_out',
        'posted_at' => substr($bookedAt, 0, 10),
        'booked_at' => $bookedAt,
        'value_date' => substr($bookedAt, 0, 10),
        'amount_minor' => -$settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => -$settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'PayPal Europe S.a.r.l. et Cie S.C.A',
        'counterparty_iban' => 'LU89751000135104200E',
        'counterparty_normalized' => 'PAYPAL EUROPE SARL ET CIE SCA',
        'normalization_version' => 3,
        'source_format' => 'camt053',
        'import_run_id' => $importRunId,
        'source_row_index' => 100 + $rowIndex,
        'fingerprint' => str_pad('asn'.$rowIndex, 64, 'a', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

it('pairs a PayPal expense with the single matching ASN transfer_out → confirmed, 1.000', function (): void {
    $f = asnDirectFixture('alpha-direct');
    $expense = paypalExpenseRow($f['user'], $f['paypal'], $f['run']->id, 1399, '2026-04-30 00:00:00');
    $asn = asnTransferOutToPaypal($f['user'], $f['bank'], $f['run']->id, 1399, '2026-04-30 12:00:00');

    $resolver = $this->app->make(PaypalFundingResolver::class);
    $resolver->resolveForUser($f['user']);

    $link = ChainLink::query()->where('user_id', $f['user']->id)->first();
    expect($link)->not->toBeNull();
    expect($link->kind)->toBe('paypal_funding');
    expect($link->state)->toBe('confirmed');
    // confidence stored as DECIMAL(4,3); SQLite returns it without
    // trailing zeros so the float comparison is the stable assertion.
    expect((float) $link->confidence)->toBe(1.0);
    expect((int) $link->from_transaction_id)->toBe($expense->id);
    expect((int) $link->to_transaction_id)->toBe($asn->id);
});

it('marks state=candidate when ≥2 ASN candidates fall inside the date window (closest-by-date wins)', function (): void {
    $f = asnDirectFixture('beta-direct');
    $expense = paypalExpenseRow($f['user'], $f['paypal'], $f['run']->id, 999, '2026-04-30 00:00:00');
    // Both ASN rows match on amount, alias and window; the closer booked_at
    // wins but the link stays candidate for review.
    $closer = asnTransferOutToPaypal($f['user'], $f['bank'], $f['run']->id, 999, '2026-04-30 12:00:00');
    asnTransferOutToPaypal($f['user'], $f['bank'], $f['run']->id, 999, '2026-04-29 12:00:00');

    $resolver = $this->app->make(PaypalFundingResolver::class);
    $resolver->resolveForUser($f['user']);

    $link = ChainLink::query()->where('user_id', $f['user']->id)->first();
    expect($link)->not->toBeNull();
    expect($link->state)->toBe('candidate');
    expect((float) $link->confidence)->toBe(0.9);
    expect((int) $link->to_transaction_id)->toBe($closer->id);
});

it('does NOT pair when no ASN row matches the amount', function (): void {
    $f = asnDirectFixture('gamma-direct');
    paypalExpenseRow($f['user'], $f['paypal'], $f['run']->id, 1399, '2026-04-30 00:00:00');
    asnTransferOutToPaypal($f['user'], $f['bank'], $f['run']->id, 2599, '2026-04-30 12:00:00');

    $resolver = $this->app->make(PaypalFundingResolver::class);
    $resolver->resolveForUser($f['user']);

    expect(ChainLink::query()->where('user_id', $f['user']->id)->count())->toBe(0);
});

it('does NOT pair when ASN row is outside the ±3-day window', function (): void {
    $f = asnDirectFixture('delta-direct');
    paypalExpenseRow($f['user'], $f['paypal'], $f['run']->id, 1399, '2026-04-30 00:00:00');
    // ASN row is 5 days earlier — outside window.
    asnTransferOutToPaypal($f['user'], $f['bank'], $f['run']->id, 1399, '2026-04-25 12:00:00');

    $resolver = $this->app->make(PaypalFundingResolver::class);
    $resolver->resolveForUser($f['user']);

    expect(ChainLink::query()->where('user_id', $f['user']->id)->count())->toBe(0);
});

it('does NOT pair when the ASN row\'s counterparty IBAN aliases to a different kind (e.g. ics_card)', function (): void {
    $f = asnDirectFixture('epsilon-direct');
    paypalExpenseRow($f['user'], $f['paypal'], $f['run']->id, 1399, '2026-04-30 00:00:00');
    // ASN row's counterparty IBAN is the ICS alias, NOT the PayPal one.
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $f['user']->id,
        'real_iban' => 'NL08ABNA0526650664',
        'target_account_kind' => 'ics_card',
    ]);
    static $idx = 1;
    Transaction::query()->create([
        'user_id' => $f['user']->id,
        'account_id' => $f['bank']->id,
        'type' => 'transfer_out',
        'posted_at' => '2026-04-30',
        'booked_at' => '2026-04-30 12:00:00',
        'value_date' => '2026-04-30',
        'amount_minor' => -1399,
        'currency' => 'EUR',
        'settled_amount_minor' => -1399,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'International Card Services B.V.',
        'counterparty_iban' => 'NL08ABNA0526650664',
        'counterparty_normalized' => 'ICS',
        'normalization_version' => 3,
        'source_format' => 'camt053',
        'import_run_id' => $f['run']->id,
        'source_row_index' => 1000,
        'fingerprint' => str_repeat('z', 64),
        'fingerprint_version' => 3,
    ]);

    $resolver = $this->app->make(PaypalFundingResolver::class);
    $resolver->resolveForUser($f['user']);

    expect(ChainLink::query()->where('user_id', $f['user']->id)->count())->toBe(0);
});

it('is per-user scoped — never pairs across users', function (): void {
    $a = asnDirectFixture('user-a-direct');
    $b = asnDirectFixture('user-b-direct');
    // Both users have identical-amount, identical-date rows.
    $aExpense = paypalExpenseRow($a['user'], $a['paypal'], $a['run']->id, 1399, '2026-04-30 00:00:00');
    $aAsn = asnTransferOutToPaypal($a['user'], $a['bank'], $a['run']->id, 1399, '2026-04-30 12:00:00');
    $bExpense = paypalExpenseRow($b['user'], $b['paypal'], $b['run']->id, 1399, '2026-04-30 00:00:00');
    $bAsn = asnTransferOutToPaypal($b['user'], $b['bank'], $b['run']->id, 1399, '2026-04-30 12:00:00');

    $resolver = $this->app->make(PaypalFundingResolver::class);
    $resolver->resolveForUser($a['user']);

    expect(ChainLink::query()->where('user_id', $a['user']->id)->count())->toBe(1);
    expect(ChainLink::query()->where('user_id', $b['user']->id)->count())->toBe(0);
    $aLink = ChainLink::query()->where('user_id', $a['user']->id)->firstOrFail();
    expect((int) $aLink->from_transaction_id)->toBe($aExpense->id);
    expect((int) $aLink->to_transaction_id)->toBe($aAsn->id);
    expect($bAsn->refresh()->id)->toBe($bAsn->id);
    expect($bExpense->refresh()->id)->toBe($bExpense->id);
});

it('does not double-claim an ASN row that is already to_transaction_id on another paypal_funding link', function (): void {
    $f = asnDirectFixture('zeta-direct');
    $firstExpense = paypalExpenseRow($f['user'], $f['paypal'], $f['run']->id, 1399, '2026-04-30 00:00:00');
    $secondExpense = paypalExpenseRow($f['user'], $f['paypal'], $f['run']->id, 1399, '2026-04-30 06:00:00', 'Microsoft');
    $asn = asnTransferOutToPaypal($f['user'], $f['bank'], $f['run']->id, 1399, '2026-04-30 12:00:00');

    $resolver = $this->app->make(PaypalFundingResolver::class);
    $resolver->resolveForUser($f['user']);

    // Exactly one link: the second expense finds no candidate because the ASN
    // row is already claimed, and falls through to fuzzy / null.
    $links = ChainLink::query()->where('user_id', $f['user']->id)->get();
    expect($links->count())->toBe(1);
    expect((int) $links->first()->to_transaction_id)->toBe($asn->id);

    // The earlier expense is the from-side (resolveForUser orders by posted_at).
    expect((int) $links->first()->from_transaction_id)->toBe($firstExpense->id);
    expect($secondExpense->refresh()->id)->toBe($secondExpense->id);
});

it('is idempotent — re-running produces zero new chain_links', function (): void {
    $f = asnDirectFixture('eta-direct');
    paypalExpenseRow($f['user'], $f['paypal'], $f['run']->id, 1399, '2026-04-30 00:00:00');
    asnTransferOutToPaypal($f['user'], $f['bank'], $f['run']->id, 1399, '2026-04-30 12:00:00');

    $resolver = $this->app->make(PaypalFundingResolver::class);
    $resolver->resolveForUser($f['user']);
    expect(ChainLink::query()->where('user_id', $f['user']->id)->count())->toBe(1);

    $resolver->resolveForUser($f['user']);
    expect(ChainLink::query()->where('user_id', $f['user']->id)->count())->toBe(1);
});
