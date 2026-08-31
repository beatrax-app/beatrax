<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Internal\Resolvers\RetypeByAliasResolver;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;

function seedIdempotencyFixture(): array
{
    $user = User::query()->create([
        'username' => 'idempotency',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $icsAccount = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ICS idempotency',
        'slug' => 'ics-idempotency',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);
    $bankAccount = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN idempotency',
        'slug' => 'asn-idempotency',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    // Seed the alias bridge so the ASN transfer_out's counterparty
    // IBAN (NL08ABNA…) resolves to the user's ics_card account.
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($user);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/idem.pdf',
        'sha256' => str_repeat('i', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    $asnRun = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/idem-asn.csv',
        'sha256' => str_repeat('j', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    // 5 expense rows summing to 5000 (€50.00 statement)
    for ($i = 1; $i <= 5; $i++) {
        Transaction::query()->create([
            'user_id' => $user->id,
            'account_id' => $icsAccount->id,
            'type' => 'expense',
            'posted_at' => '2026-04-1'.$i,
            'booked_at' => '2026-04-1'.$i.' 12:00:00',
            'value_date' => '2026-04-1'.$i,
            'amount_minor' => -1000,
            'currency' => 'EUR',
            'settled_amount_minor' => -1000,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Idem Merchant '.$i,
            'counterparty_normalized' => 'idem-'.$i,
            'normalization_version' => 1,
            'source_format' => 'ics-pdf',
            'import_run_id' => $run->id,
            'source_row_index' => $i,
            'fingerprint' => str_pad('idmx'.$i, 64, 'x', STR_PAD_LEFT),
            'fingerprint_version' => 3,
        ]);
    }
    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $bankAccount->id,
        'type' => 'transfer_out',
        'posted_at' => '2026-04-28',
        'booked_at' => '2026-04-28 12:00:00',
        'value_date' => '2026-04-28',
        'amount_minor' => -5000,
        'currency' => 'EUR',
        'settled_amount_minor' => -5000,
        'settled_currency' => 'EUR',
        'counterparty_iban' => 'NL08ABNA0526650664',
        'counterparty_name' => 'ASN Bulk Idem',
        'counterparty_normalized' => 'asn-bulk-idem',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $asnRun->id,
        'source_row_index' => 999,
        'fingerprint' => str_pad('idmt', 64, 'y', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
    CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $icsAccount->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -5000,
        'open_balance_minor' => 5000,
        'state' => 'open',
    ]);

    return ['user' => $user];
}

// Counting chain_links alone missed the half of the contract that hurts: the
// state machine's writes are not INSERTs, so a second pass could re-apply the
// same settlement amount without adding a single row.
function idempotencySettlementState(int $userId): array
{
    /** @var CardStatement $statement */
    $statement = CardStatement::query()->where('user_id', $userId)->firstOrFail();

    return [
        'state' => $statement->state,
        'open_balance_minor' => $statement->open_balance_minor,
        'credits' => DB::table('card_statement_credits')->where('user_id', $userId)->count(),
    ];
}

it('re-running the resolver job produces zero additional chain_links and leaves the settlement where it was', function (): void {
    $fixture = seedIdempotencyFixture();
    $user = $fixture['user'];
    $db = $this->app->make(DatabaseManager::class);
    $clock = $this->app->make(Clock::class);
    $ics = $this->app->make(IcsSettlementResolver::class);
    $paypal = $this->app->make(PaypalFundingResolver::class);
    $retype = $this->app->make(RetypeByAliasResolver::class);
    $pairer = $this->app->make(PairsTransferLegs::class);
    $upserter = $this->app->make(UpsertsCardStatements::class);

    $job = new ResolveChainLinksJob($user->id);
    $job->handle($db, $clock, $retype, $pairer, $upserter, $ics, $paypal);
    $countAfterFirst = ChainLink::query()->where('user_id', $user->id)->count();
    $settlementAfterFirst = idempotencySettlementState($user->id);
    expect($countAfterFirst)->toBe(5);

    $job = new ResolveChainLinksJob($user->id);
    $job->handle($db, $clock, $retype, $pairer, $upserter, $ics, $paypal);
    $countAfterSecond = ChainLink::query()->where('user_id', $user->id)->count();

    expect($countAfterSecond)->toBe($countAfterFirst);
    expect(idempotencySettlementState($user->id))->toBe($settlementAfterFirst);
});

// A transfer inside tolerance that covers no expense writes no chain_link, and
// the candidate query excludes only transfers that carry one — so every later
// pass found it again and subtracted its amount again. Three passes walked an
// open statement to partially_settled, then to overpaid, and minted a credit
// for money nobody paid.
function seedSettlementThatLinksNothingFixture(): User
{
    $user = User::query()->create([
        'username' => 'links-nothing',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $icsAccount = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ICS links-nothing',
        'slug' => 'ics-links-nothing',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($user);

    $asnRun = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/links-nothing.csv',
        'sha256' => str_repeat('n', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    $bankAccount = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN links-nothing',
        'slug' => 'asn-links-nothing',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    // EUR 7.00 against a EUR 500.00 statement: 2% of the statement is EUR 10,
    // so the payment sits inside tolerance while covering no expense at all.
    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $bankAccount->id,
        'type' => 'transfer_out',
        'posted_at' => '2026-04-28',
        'booked_at' => '2026-04-28 12:00:00',
        'value_date' => '2026-04-28',
        'amount_minor' => -700,
        'currency' => 'EUR',
        'settled_amount_minor' => -700,
        'settled_currency' => 'EUR',
        'counterparty_iban' => 'NL08ABNA0526650664',
        'counterparty_name' => 'ASN Small Payment',
        'counterparty_normalized' => 'asn-small-payment',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $asnRun->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('lnth', 64, 'z', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
    CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $icsAccount->id,
        'import_run_id' => null,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -50000,
        'open_balance_minor' => 50000,
        'state' => 'open',
    ]);

    return $user;
}

it('leaves the statement alone when an in-tolerance settlement covers no expense', function (): void {
    $user = seedSettlementThatLinksNothingFixture();
    $ics = $this->app->make(IcsSettlementResolver::class);

    $ics->resolveForUser($user);
    $afterFirst = idempotencySettlementState($user->id);

    $ics->resolveForUser($user);
    $ics->resolveForUser($user);

    expect($afterFirst)->toBe([
        'state' => 'open',
        'open_balance_minor' => 50000,
        'credits' => 0,
    ]);
    expect(idempotencySettlementState($user->id))->toBe($afterFirst);
});

it('rejected pairs stay rejected — the pair-uniqueness guard blocks re-proposal', function (): void {
    $fixture = seedIdempotencyFixture();
    $user = $fixture['user'];
    $ics = $this->app->make(IcsSettlementResolver::class);

    $ics->resolveForUser($user);
    expect(ChainLink::query()->where('user_id', $user->id)->count())->toBe(5);

    /** @var ChainLink $first */
    $first = ChainLink::query()->where('user_id', $user->id)->firstOrFail();
    $first->state = 'rejected';
    $first->save();

    // The pair-uniqueness guard checks ALL states, so a rejected pair is
    // never re-proposed.
    $ics->resolveForUser($user);
    expect(ChainLink::query()->where('user_id', $user->id)->count())->toBe(5);

    $stillRejected = ChainLink::query()->where('id', $first->id)->firstOrFail();
    expect($stillRejected->state)->toBe('rejected');
});
