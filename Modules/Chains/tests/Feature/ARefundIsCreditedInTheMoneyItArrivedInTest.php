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

// The refund-after-close pass is the one settlement path that never asked which
// money a row is in. resolveOne() refuses a payment in another currency,
// pullExpenses() totals only the charges the statement is denominated in and
// priorCreditsMinor() sums only credits carrying its code -- and this arm took a
// refund's magnitude whatever it settled in, paired it against a charge of that
// magnitude in any money, and wrote it under the closed statement's currency,
// where that same sum then spends it against the next statement.

/**
 * @return array{user: User, ics: Account, run: ImportRun, closed: CardStatement, next: CardStatement}
 */
function refundAfterCloseFixture(string $username, string $refundCurrency, string $originalCurrency = 'EUR'): array
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
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($user);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/'.$username.'.pdf',
        'sha256' => hash('sha256', $username),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    refundCardCharge($user, $ics, $run, 'Kappabashi Dougu', '2026-04-05', -5000, $originalCurrency, 1, 'expense');
    refundCardCharge($user, $ics, $run, 'Kappabashi Dougu', '2026-04-20', 5000, $refundCurrency, 2, 'refund');

    /** @var CardStatement $closed */
    $closed = CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -50000,
        'open_balance_minor' => 0,
        'currency' => 'EUR',
        'state' => 'settled',
    ]);

    /** @var CardStatement $next */
    $next = CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-05-01 00:00:00',
        'period_end' => '2026-05-31 23:59:59',
        'total_amount_minor' => -20000,
        'open_balance_minor' => 20000,
        'currency' => 'EUR',
        'state' => 'open',
    ]);

    return ['user' => $user, 'ics' => $ics, 'run' => $run, 'closed' => $closed, 'next' => $next];
}

function refundCardCharge(
    User $user,
    Account $account,
    ImportRun $run,
    string $merchant,
    string $day,
    int $minor,
    string $currency,
    int $rowIndex,
    string $type,
): void {
    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type,
        'posted_at' => $day,
        'booked_at' => $day.' 12:00:00',
        'value_date' => $day,
        'amount_minor' => $minor,
        'currency' => $currency,
        'settled_amount_minor' => $minor,
        'settled_currency' => $currency,
        'counterparty_name' => $merchant,
        'counterparty_normalized' => strtolower(str_replace(' ', '-', $merchant)),
        'normalization_version' => 1,
        'source_format' => 'ics-pdf',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => hash('sha256', $user->username.'-'.$type.'-'.$rowIndex),
        'fingerprint_version' => 3,
    ]);
}

it('carries a refund that arrived in the statement currency forward as a credit', function (): void {
    ['user' => $user, 'next' => $next] = refundAfterCloseFixture('refund-same-money', 'EUR');

    app(IcsSettlementResolver::class)->resolveForUser($user);

    $credit = DB::table('card_statement_credits')->where('user_id', $user->id)->first();

    expect(DB::table('card_statement_credits')->where('user_id', $user->id)->count())->toBe(1)
        ->and($credit?->currency)->toBe('EUR')
        ->and($credit?->amount_minor)->toBe(5000)
        ->and($credit?->to_statement_id)->toBe($next->id);
});

// The destination is named in a currency too, the one call site of
// nextOpenStatementId() that used to name none. A statement in another money
// can never count this credit -- priorCreditsMinor() sums only the statement's
// own -- so the pointer is left open for attachDanglingCredits() to close when
// a statement that can count it lands, rather than parked where nothing will.
it('leaves the credit unattached rather than pointing it at a statement that could never count it', function (): void {
    ['user' => $user, 'next' => $next] = refundAfterCloseFixture('refund-no-home-yet', 'EUR');

    CardStatement::query()->whereKey($next->id)->update(['currency' => 'USD']);

    app(IcsSettlementResolver::class)->resolveForUser($user);

    $credit = DB::table('card_statement_credits')->where('user_id', $user->id)->first();

    expect($credit?->currency)->toBe('EUR')
        ->and($credit?->amount_minor)->toBe(5000)
        ->and($credit?->to_statement_id)->toBeNull();
});

it('writes no credit for a refund that arrived in a money the statement is not in', function (): void {
    ['user' => $user] = refundAfterCloseFixture('refund-foreign-money', 'JPY');

    app(IcsSettlementResolver::class)->resolveForUser($user);

    expect(DB::table('card_statement_credits')->where('user_id', $user->id)->count())->toBe(0);
});

it('does not pair a refund with a charge of equal magnitude in another money', function (): void {
    ['user' => $user] = refundAfterCloseFixture('refund-foreign-pairing', 'JPY');

    app(IcsSettlementResolver::class)->resolveForUser($user);

    expect(ChainLink::query()->where('user_id', $user->id)->count())->toBe(0);
});

// The consequence, measured rather than asserted from the mechanism: the credit
// is read back by priorCreditsMinor(), which cannot tell a mislabelled one from
// a real one, so a statement the reader paid EUR 50.00 short of settles anyway.
it('leaves a statement short of its payment open when the only thing closing it is a foreign refund', function (): void {
    ['user' => $user, 'ics' => $ics, 'run' => $run, 'next' => $next] = refundAfterCloseFixture('refund-foreign-settles', 'JPY');

    refundCardCharge($user, $ics, $run, 'Hifi Klubben', '2026-05-10', -20000, 'EUR', 3, 'expense');

    $bank = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'asn-refund-foreign-settles',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $bank->id,
        'type' => 'transfer_out',
        'posted_at' => '2026-06-05',
        'booked_at' => '2026-06-05 12:00:00',
        'value_date' => '2026-06-05',
        'amount_minor' => -15000,
        'currency' => 'EUR',
        'settled_amount_minor' => -15000,
        'settled_currency' => 'EUR',
        'counterparty_iban' => 'NL08ABNA0526650664',
        'counterparty_name' => 'ASN Bulk',
        'counterparty_normalized' => 'asn-bulk',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 999,
        'fingerprint' => hash('sha256', 'refund-foreign-settles-transfer'),
        'fingerprint_version' => 3,
    ]);

    $resolver = app(IcsSettlementResolver::class);
    $resolver->resolveForUser($user);
    $resolver->resolveForUser($user);

    expect(CardStatement::query()->findOrFail($next->id)->state)->toBe('open')
        ->and(CardStatement::query()->findOrFail($next->id)->open_balance_minor)->toBe(20000);
});
