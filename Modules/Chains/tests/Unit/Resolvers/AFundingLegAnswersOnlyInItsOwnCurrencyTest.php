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

// The deterministic arm learnt this the expensive way: both sides are compared
// as bare minor units, so USD 13.99 answers EUR 13.99 unless the currency is
// part of the question. The other two arms compare the same bare integers.

/**
 * @return array{user: User, bank: Account, paypal: Account, run: ImportRun}
 */
function focFixture(string $username): array
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
        'name' => sprintf('FOC-ASN-%d', $counter),
        'slug' => sprintf('foc-asn-%d', $counter),
        'kind' => 'bank',
        'iban' => sprintf('NL12ASNB%010d', 900 + $counter),
        'default_currency' => 'EUR',
    ]);
    $paypal = Account::query()->create([
        'user_id' => $user->id,
        'name' => sprintf('FOC-PayPal-%d', $counter),
        'slug' => sprintf('foc-paypal-%d', $counter),
        'kind' => 'paypal',
        'iban' => 'FOC-PAYPAL-'.$counter,
        'default_currency' => 'USD',
    ]);
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $user->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => sprintf('/tmp/foc-%d', $counter),
        'sha256' => str_pad((string) $counter, 64, 'f', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    return compact('user', 'bank', 'paypal', 'run');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function focTx(User $user, Account $account, ImportRun $run, string $type, int $settledMinor, string $currency, string $merchant, string $bookedAt, array $overrides = []): Transaction
{
    static $rowIndex = 0;
    $rowIndex++;

    return Transaction::query()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type,
        'posted_at' => substr($bookedAt, 0, 10),
        'booked_at' => $bookedAt,
        'value_date' => substr($bookedAt, 0, 10),
        'amount_minor' => $settledMinor,
        'currency' => $currency,
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => $currency,
        'counterparty_name' => $merchant,
        'counterparty_normalized' => strtoupper($merchant),
        'normalization_version' => 3,
        'source_format' => 'paypal-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 500 + $rowIndex,
        'fingerprint' => str_pad('foc'.$rowIndex, 64, 'c', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ], $overrides));
}

it('does not read a EUR bank debit as the funding leg of a USD PayPal charge of the same number', function (): void {
    $f = focFixture('foc-asn-direct');
    focTx($f['user'], $f['paypal'], $f['run'], 'expense', -1399, 'USD', 'Google Cloud EMEA Limited', '2026-04-30 00:00:00');
    focTx($f['user'], $f['bank'], $f['run'], 'transfer_out', -1399, 'EUR', 'PayPal Europe S.a.r.l. et Cie S.C.A', '2026-04-30 12:00:00', [
        'counterparty_iban' => 'LU89751000135104200E',
    ]);

    $resolver = $this->app->make(PaypalFundingResolver::class);
    $resolver->resolveForUser($f['user']);

    expect(ChainLink::query()->where('user_id', $f['user']->id)->count())->toBe(0);
});

it('does not score a EUR deposit against a USD PayPal charge of the same number', function (): void {
    $f = focFixture('foc-fuzzy');
    focTx($f['user'], $f['paypal'], $f['run'], 'expense', -1599, 'USD', 'Netflix International BV', '2026-04-30 00:00:00');
    focTx($f['user'], $f['bank'], $f['run'], 'transfer_in', 1599, 'EUR', 'Netflix International BV', '2026-04-30 12:00:00');

    $resolver = $this->app->make(PaypalFundingResolver::class);
    $resolver->resolveForUser($f['user']);

    expect(ChainLink::query()->where('user_id', $f['user']->id)->count())->toBe(0);
});

it('still links the two arms when both legs are denominated the same', function (): void {
    $f = focFixture('foc-same-currency');
    focTx($f['user'], $f['paypal'], $f['run'], 'expense', -1399, 'EUR', 'Google Cloud EMEA Limited', '2026-04-30 00:00:00');
    $asn = focTx($f['user'], $f['bank'], $f['run'], 'transfer_out', -1399, 'EUR', 'PayPal Europe S.a.r.l. et Cie S.C.A', '2026-04-30 12:00:00', [
        'counterparty_iban' => 'LU89751000135104200E',
    ]);

    $resolver = $this->app->make(PaypalFundingResolver::class);
    $resolver->resolveForUser($f['user']);

    $link = ChainLink::query()->where('user_id', $f['user']->id)->first();
    expect($link)->not->toBeNull()
        ->and($link->state)->toBe('confirmed')
        ->and((int) $link->to_transaction_id)->toBe($asn->id);
});
