<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Modules\Chains\Internal\Resolvers\RetypeByAliasResolver;
use Modules\Core\Models\User;
use Modules\Import\Models\KnownCounterpartyIban;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Public\Events\TransactionMutated;

// Chain resolution retyped in raw SQL — `UPDATE transactions SET type = ?` over
// a batch of ids — a spelling no capture guard reads and no peer ever heard. The
// desktop netted a pair of legs out of its spending while the phone went on
// counting the same two rows as an expense and an income, with nothing anywhere
// saying the two disagreed.

/**
 * @return array{user: User, account: Account, run: ImportRun}
 */
function retypeAnnounceFixture(): array
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => 'retype-announce',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    /** @var Account $account */
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN retype fixture',
        'slug' => 'asn-retype-fixture',
        'kind' => 'bank',
        'iban' => 'NL12ASNB0987654321',
        'default_currency' => 'EUR',
    ]);

    // The alias bridge the resolver reads: a counterparty IBAN that is really
    // one of the reader's own PayPal legs, so the row is a transfer.
    Account::query()->create([
        'user_id' => $user->id,
        'name' => 'PayPal retype fixture',
        'slug' => 'paypal-retype-fixture',
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);

    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $user->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);

    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/retype-announce.csv',
        'sha256' => str_repeat('r', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    return ['user' => $user, 'account' => $account, 'run' => $run];
}

/**
 * @param  array{user: User, account: Account, run: ImportRun}  $fixture
 */
function retypeAnnounceTransaction(array $fixture, string $type, int $amountMinor): Transaction
{
    static $row = 0;
    $row++;

    /** @var Transaction $transaction */
    $transaction = Transaction::query()->create([
        'user_id' => $fixture['user']->id,
        'account_id' => $fixture['account']->id,
        'import_run_id' => $fixture['run']->id,
        'type' => $type,
        'posted_at' => '2026-04-30',
        'booked_at' => '2026-04-30 12:00:00',
        'value_date' => '2026-04-30',
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Retype fixture',
        'counterparty_iban' => 'LU89751000135104200E',
        'counterparty_normalized' => 'RETYPE-FIXTURE',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => $row,
        'fingerprint' => hash('sha256', 'retype-announce-'.$row),
        'fingerprint_version' => 1,
    ]);

    return $transaction;
}

/** @return list<TransactionMutated> the type edits this pass announced */
function retypeAnnouncements(): array
{
    $seen = [];

    foreach (Event::dispatched(TransactionMutated::class) as $dispatch) {
        $event = $dispatch[0];

        if ($event instanceof TransactionMutated && array_key_exists('type', $event->dirtyFields)) {
            $seen[] = $event;
        }
    }

    return $seen;
}

it('announces the type it retyped, so the peer stops counting the leg as spending', function (): void {
    // Faked before the resolver is built: Event::fake() replaces the binding,
    // and the writer resolves the dispatcher per dispatch for exactly that.
    Event::fake([TransactionMutated::class]);

    $fixture = retypeAnnounceFixture();
    $transaction = retypeAnnounceTransaction($fixture, 'expense', -1399);

    $touched = $this->app->make(RetypeByAliasResolver::class)->resolveForUser($fixture['user']);

    expect($touched)->toBe(1)
        ->and(Transaction::query()->find($transaction->id)?->type)->toBe('transfer_out');

    $announced = retypeAnnouncements();

    expect($announced)->toHaveCount(1)
        ->and($announced[0]->transactionId)->toBe($transaction->id)
        ->and($announced[0]->userId)->toBe($fixture['user']->id)
        ->and($announced[0]->mutationType)->toBe('edit')
        ->and($announced[0]->dirtyFields)->toBe(['type' => 'transfer_out']);
});

it('tells the peer nothing on a second pass that moved no row', function (): void {
    Event::fake([TransactionMutated::class]);

    $fixture = retypeAnnounceFixture();
    retypeAnnounceTransaction($fixture, 'income', 4567);

    $resolver = $this->app->make(RetypeByAliasResolver::class);

    expect($resolver->resolveForUser($fixture['user']))->toBe(1)
        ->and(retypeAnnouncements())->toHaveCount(1);

    // An op for a change that did not happen is a Set the peer applies for
    // nothing, and on a g_counter column it would be worse than nothing.
    expect($resolver->resolveForUser($fixture['user']))->toBe(0)
        ->and(retypeAnnouncements())->toHaveCount(1);
});
