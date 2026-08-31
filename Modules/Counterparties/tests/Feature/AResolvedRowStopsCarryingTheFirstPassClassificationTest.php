<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Counterparties\Internal\Jobs\CounterpartyGarbageCollectorJob;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Counterparties\Public\Queries\CounterpartyTriageQueue;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Events\TransactionMutated;

// upsert() was a firstOrCreate: it wrote on create and never again. The DTO it
// returned reported the fresh classification while the stored row kept the
// first pass's, which is a row the triage queue (type='unknown' strictly) can
// never show as resolved, and a merchant row the garbage collector's
// null-merchant_name half is free to prune.

function refreshedUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function refreshedAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
}

function refreshedCanonical(User $user, Account $account, ?string $iban, ?string $description): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: $user->id,
        accountId: $account->id,
        type: 'transfer_out',
        postedAt: CarbonImmutable::parse('2026-03-01'),
        bookedAt: CarbonImmutable::parse('2026-03-01 12:00:00'),
        valueDate: CarbonImmutable::parse('2026-03-01'),
        amountMinor: -2500,
        currency: 'EUR',
        settledAmountMinor: -2500,
        settledCurrency: 'EUR',
        counterpartyName: 'Jan de Vries',
        counterpartyIban: $iban,
        counterpartyNormalized: 'jan-de-vries',
        normalizationVersion: 1,
        description: $description,
        categoryId: null,
        sourceFormat: 'asn-csv',
        importRunId: 1,
        sourceRowIndex: 1,
        sourceRef: 'refresh:1',
    );
}

it('writes the later classification onto the row a thinner pass created', function (): void {
    $user = refreshedUser('refreshed-classification');
    $account = refreshedAccount($user, 'refreshed-asn');

    /** @var CounterpartyResolver $resolver */
    $resolver = app(CounterpartyResolver::class);

    // First pass: a name and nothing else, so the row lands `unknown`.
    $first = $resolver->resolve(refreshedCanonical($user, $account, null, null), $user);
    expect($first?->type)->toBe(CounterpartyType::Unknown->value);

    // Second pass: the same name, now with a counterparty IBAN, so the
    // personal tier claims it.
    $second = $resolver->resolve(
        refreshedCanonical($user, $account, 'NL91ABNA0417164300', 'OVERBOEKING'),
        $user,
    );

    expect($second?->type)->toBe(CounterpartyType::Personal->value);
    expect($second?->counterpartyId)->toBe($first?->counterpartyId);

    /** @var Counterparty $stored */
    $stored = Counterparty::query()->findOrFail($second?->counterpartyId);

    expect($stored->type)->toBe(CounterpartyType::Personal->value);
    expect($stored->iban)->toBe('NL91ABNA0417164300');
});

it('lets a row the triage queue was holding leave the queue once it classifies', function (): void {
    $user = refreshedUser('refreshed-triage');
    $account = refreshedAccount($user, 'refreshed-triage-asn');

    /** @var CounterpartyResolver $resolver */
    $resolver = app(CounterpartyResolver::class);

    $resolver->resolve(refreshedCanonical($user, $account, null, null), $user);

    /** @var CounterpartyTriageQueue $queue */
    $queue = app(CounterpartyTriageQueue::class);
    expect($queue->forUser($user))->toHaveCount(1);

    $resolver->resolve(
        refreshedCanonical($user, $account, 'NL91ABNA0417164300', 'OVERBOEKING'),
        $user,
    );

    expect($queue->forUser($user))->toBeEmpty();
});

it('tells the peer about the columns the later pass rewrote', function (): void {
    $user = refreshedUser('refreshed-sync');
    $account = refreshedAccount($user, 'refreshed-sync-asn');

    /** @var CounterpartyResolver $resolver */
    $resolver = app(CounterpartyResolver::class);
    $resolver->resolve(refreshedCanonical($user, $account, null, null), $user);

    $edits = [];
    app(Dispatcher::class)->listen(EntityMutated::class, static function (EntityMutated $event) use (&$edits): void {
        if ($event->table === 'counterparties' && $event->mutationType === 'edit') {
            $edits[] = $event;
        }
    });

    $resolver->resolve(
        refreshedCanonical($user, $account, 'NL91ABNA0417164300', 'OVERBOEKING'),
        $user,
    );

    expect($edits)->toHaveCount(1);
    expect($edits[0]->dirtyFields)->toHaveKey('type');
    expect($edits[0]->dirtyFields['iban'])->toBe('NL91ABNA0417164300');
});

it('says nothing to the peer when the later pass resolves to the same values', function (): void {
    $user = refreshedUser('refreshed-quiet');
    $account = refreshedAccount($user, 'refreshed-quiet-asn');

    /** @var CounterpartyResolver $resolver */
    $resolver = app(CounterpartyResolver::class);
    $resolver->resolve(refreshedCanonical($user, $account, 'NL91ABNA0417164300', 'OVERBOEKING'), $user);

    $edits = 0;
    app(Dispatcher::class)->listen(EntityMutated::class, static function (EntityMutated $event) use (&$edits): void {
        if ($event->table === 'counterparties' && $event->mutationType === 'edit') {
            $edits++;
        }
    });

    $resolver->resolve(refreshedCanonical($user, $account, 'NL91ABNA0417164300', 'OVERBOEKING'), $user);

    expect($edits)->toBe(0);
});

// The garbage collector NULLed the FK and DELETEd the rows through the query
// builder, announcing neither — so a peer kept counterparties this device had
// dropped, and transactions still pointing at them.
it('announces the counterparties it deleted and the links it broke', function (): void {
    $user = refreshedUser('refreshed-gc');
    $account = refreshedAccount($user, 'refreshed-gc-asn');

    /** @var Counterparty $orphan */
    $orphan = Counterparty::query()->create([
        'user_id' => $user->id,
        'type' => CounterpartyType::Unknown->value,
        'slug' => 'gc-orphan',
        'display_name' => 'GC Orphan',
        'merchant_name' => null,
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/refreshed-gc.csv',
        'sha256' => hash('sha256', 'refreshed-gc'),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    /** @var Transaction $tx */
    $tx = Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2023-01-01',
        'booked_at' => '2023-01-01 12:00:00',
        'value_date' => '2023-01-01',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'GC Orphan',
        'counterparty_normalized' => 'gc-orphan',
        'normalization_version' => 1,
        'counterparty_id' => $orphan->id,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('gcorph', 64, 'g', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);

    // The retention window is measured on transactions.created_at, which the
    // model stamps at now(); the row has to look old for the prune to reach it.
    DB::table('transactions')
        ->where('id', $tx->id)
        ->update(['created_at' => CarbonImmutable::parse('2023-01-01 12:00:00')->toDateTimeString()]);

    $deletes = [];
    $unlinks = [];
    $events = app(Dispatcher::class);
    $events->listen(EntityMutated::class, static function (EntityMutated $event) use (&$deletes): void {
        if ($event->table === 'counterparties' && $event->mutationType === 'delete') {
            $deletes[] = $event->pk;
        }
    });
    $events->listen(TransactionMutated::class, static function (TransactionMutated $event) use (&$unlinks): void {
        $unlinks[] = $event->transactionId;
    });

    (new CounterpartyGarbageCollectorJob($user->id))->handle(
        app(DatabaseManager::class),
        app(Clock::class),
        null,
        null,
        null,
        null,
        null,
        $events,
    );

    expect(Counterparty::query()->where('id', $orphan->id)->exists())->toBeFalse();
    expect($deletes)->toBe([$orphan->id]);
    expect($unlinks)->toBe([$tx->id]);
});
