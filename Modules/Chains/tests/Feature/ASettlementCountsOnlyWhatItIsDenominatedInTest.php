<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// card_statement_credits grew a currency column and the credit sum never read
// it, so a USD 20 credit was subtracted from a EUR 500 statement — enough to
// push a fully-paid statement out of tolerance and leave it open.

/**
 * @return array{user: User, statement: CardStatement}
 */
function currencyScopedFixture(string $username, int $settledMinor, string $payerCurrency = 'EUR', string $expenseCurrency = 'EUR'): array
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $ics = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ICS '.$username,
        'slug' => 'ics-'.$username,
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);
    $bank = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN '.$username,
        'slug' => 'asn-'.$username,
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($user);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/'.$username.'.pdf',
        'sha256' => hash('sha256', $username),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    for ($i = 1; $i <= 5; $i++) {
        Transaction::query()->create([
            'user_id' => $user->id,
            'account_id' => $ics->id,
            'type' => 'expense',
            'posted_at' => '2026-04-1'.$i,
            'booked_at' => '2026-04-1'.$i.' 12:00:00',
            'value_date' => '2026-04-1'.$i,
            'amount_minor' => -10000,
            'currency' => $expenseCurrency,
            'settled_amount_minor' => -10000,
            'settled_currency' => $expenseCurrency,
            'counterparty_name' => 'Merchant '.$i,
            'counterparty_normalized' => 'ccy-'.$i,
            'normalization_version' => 1,
            'source_format' => 'ics-pdf',
            'import_run_id' => $run->id,
            'source_row_index' => $i,
            'fingerprint' => hash('sha256', $username.'-expense-'.$i),
            'fingerprint_version' => 3,
        ]);
    }

    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $bank->id,
        'type' => 'transfer_out',
        'posted_at' => '2026-04-28',
        'booked_at' => '2026-04-28 12:00:00',
        'value_date' => '2026-04-28',
        'amount_minor' => -$settledMinor,
        'currency' => $payerCurrency,
        'settled_amount_minor' => -$settledMinor,
        'settled_currency' => $payerCurrency,
        'counterparty_iban' => 'NL08ABNA0526650664',
        'counterparty_name' => 'ASN Bulk',
        'counterparty_normalized' => 'asn-bulk',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 999,
        'fingerprint' => hash('sha256', $username.'-transfer'),
        'fingerprint_version' => 3,
    ]);

    /** @var CardStatement $statement */
    $statement = CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -50000,
        'open_balance_minor' => 50000,
        'currency' => 'EUR',
        'state' => 'open',
    ]);

    return ['user' => $user, 'statement' => $statement];
}

it('ignores a credit carried forward in another currency when it totals the statement', function (): void {
    ['user' => $user, 'statement' => $statement] = currencyScopedFixture('ccy-scoped', 50000);

    DB::table('card_statement_credits')->insert([
        'user_id' => $user->id,
        'from_statement_id' => $statement->id,
        'to_statement_id' => $statement->id,
        'amount_minor' => 2000,
        'currency' => 'USD',
        'reason' => 'overpayment',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    app(IcsSettlementResolver::class)->resolveForUser($user);

    expect(CardStatement::query()->findOrFail($statement->id)->state)->toBe('settled');
});

// The doc and the in-file comment both said positive = overpaid; the arithmetic
// said the opposite, and the hint printed the number either way.
it('stores a positive unaccounted delta when the reader paid more than the statement asked', function (): void {
    ['user' => $user] = currencyScopedFixture('ccy-over', 50800);

    app(IcsSettlementResolver::class)->resolveForUser($user);

    /** @var ChainLink $link */
    $link = ChainLink::query()->where('user_id', $user->id)->firstOrFail();

    expect($link->evidence['unaccounted_delta_minor'])->toBe(800);
});

it('stores a negative unaccounted delta when the reader paid less than the statement asked', function (): void {
    ['user' => $user] = currencyScopedFixture('ccy-under', 49200);

    app(IcsSettlementResolver::class)->resolveForUser($user);

    /** @var ChainLink $link */
    $link = ChainLink::query()->where('user_id', $user->id)->firstOrFail();

    expect($link->evidence['unaccounted_delta_minor'])->toBe(-800);
});

// Every term of the delta is a bare minor unit, so a payment in another
// currency was added straight onto a EUR statement total: USD 500.00 closed a
// EUR 500.00 statement and recorded an unaccounted delta of zero, while the
// USD 543.00 that actually covered it was refused and the statement stayed
// open for good.
it('refuses to settle a statement from a payment denominated in another currency', function (): void {
    ['user' => $user, 'statement' => $statement] = currencyScopedFixture('ccy-foreign-payer', 50000, 'USD');

    app(IcsSettlementResolver::class)->resolveForUser($user);

    expect(CardStatement::query()->findOrFail($statement->id)->state)->toBe('open');
    expect(CardStatement::query()->findOrFail($statement->id)->open_balance_minor)->toBe(50000);
    expect(ChainLink::query()->where('user_id', $user->id)->count())->toBe(0);
});

// The same amount of a different money is not the same amount: without the
// currency test the delta read as balanced and the pass wrote a whole
// statement's worth of confirmed links off it.
it('does not read a foreign payment of equal magnitude as a balanced settlement', function (): void {
    ['user' => $user] = currencyScopedFixture('ccy-foreign-delta', 54300, 'USD');

    app(IcsSettlementResolver::class)->resolveForUser($user);

    expect(ChainLink::query()->where('user_id', $user->id)->count())->toBe(0);
});

// The expense sum is the other half of the same arithmetic: charges billed in
// another currency are not what this statement is denominated in.
it('totals only the charges denominated in the statement currency', function (): void {
    ['user' => $user, 'statement' => $statement] = currencyScopedFixture('ccy-foreign-legs', 50000, 'EUR', 'USD');

    app(IcsSettlementResolver::class)->resolveForUser($user);

    expect(CardStatement::query()->findOrFail($statement->id)->state)->toBe('open');
    expect(ChainLink::query()->where('user_id', $user->id)->where('state', 'confirmed')->count())->toBe(0);

    // Covering nothing the statement is denominated in is a payment the pass
    // cannot account for, which is what the review queue is for.
    /** @var ChainLink $hint */
    $hint = ChainLink::query()->where('user_id', $user->id)->firstOrFail();
    expect($hint->state)->toBe('candidate');
    expect($hint->to_transaction_id)->toBeNull();
});
