<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Import\Public\Enums\EnrichmentConflictField;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Receipts\Public\Actions\ApplyReceiptConflictResolution;
use Modules\Receipts\Public\Enums\ReceiptConflictChoice;
use Modules\Sync\Public\Events\TransactionMutated;

// Answering the toast rewrote the amount, the currency, the counterparty name
// and the fingerprint with nothing announced: the desktop read EUR 48.20 and
// the phone EUR 50.00 forever, and the peer's stale fingerprint meant the same
// statement re-imported there landed a second row instead of matching.

function arcrpUser(): User
{
    return User::query()->create([
        'username' => 'arcrp-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function arcrpAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN arcrp',
        'slug' => 'arcrp-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
}

/**
 * @return array{tx: Transaction, conflictId: int}
 */
function arcrpSeedConflict(
    User $user,
    Account $account,
    EnrichmentConflictField $field,
    mixed $stored,
    mixed $incoming,
    string $status = 'cleared',
): array {
    static $idx = 0;
    $idx++;

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/arcrp-'.$idx.'.csv',
        'sha256' => hash('sha256', 'arcrp-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'confirmed',
    ]);

    $tx = Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-04-01',
        'booked_at' => '2026-04-01 12:00:'.str_pad((string) $idx, 2, '0', STR_PAD_LEFT),
        'value_date' => '2026-04-01',
        'amount_minor' => $field === EnrichmentConflictField::AmountMinor ? $stored : -5000,
        'currency' => 'EUR',
        'settled_amount_minor' => -5000,
        'settled_currency' => 'EUR',
        'counterparty_name' => $field === EnrichmentConflictField::CounterpartyName ? $stored : 'ARCRP MERCHANT',
        'counterparty_normalized' => 'arcrp merchant',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $idx,
        'fingerprint' => str_pad('arcrp-'.$idx, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'status' => $status,
    ]);

    $conflictId = (int) app(DatabaseManager::class)->connection()->table('pending_enrichment_conflicts')->insertGetId([
        'user_id' => $user->id,
        'transaction_id' => $tx->id,
        'field_name' => $field->value,
        'stored_value' => json_encode($stored, JSON_THROW_ON_ERROR),
        'incoming_value' => json_encode($incoming, JSON_THROW_ON_ERROR),
        'incoming_source_format' => 'eml',
        'import_run_id' => $run->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['tx' => $tx, 'conflictId' => $conflictId];
}

it('announces the resolved amount and the recomposed fingerprint together', function (): void {
    $user = arcrpUser();
    $account = arcrpAccount($user);
    $seeded = arcrpSeedConflict($user, $account, EnrichmentConflictField::AmountMinor, stored: -5000, incoming: -4820);

    Event::fake([TransactionMutated::class]);

    /** @var ApplyReceiptConflictResolution $resolve */
    $resolve = app(ApplyReceiptConflictResolution::class);
    $resolve($user, ReceiptConflictChoice::PreferReceipt, $seeded['conflictId']);

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $seeded['tx']->id)->first();
    expect((int) $row->amount_minor)->toBe(-4820);

    // The peer replays these fields verbatim, so the announcement has to carry
    // the tuple as well as the value or the two devices agree on the amount
    // and disagree on the dedup key that decides a re-import.
    Event::assertDispatched(TransactionMutated::class, function (TransactionMutated $e) use ($seeded, $user, $row): bool {
        return $e->transactionId === $seeded['tx']->id
            && $e->userId === $user->id
            && $e->mutationType === 'edit'
            && ($e->dirtyFields['amount_minor'] ?? null) === -4820
            && ($e->dirtyFields['fingerprint'] ?? null) === $row->fingerprint
            && ($e->dirtyFields['fingerprint_version'] ?? null) === (int) $row->fingerprint_version;
    });
});

it('announces the counterparty name in plaintext, with the re-keyed normalized column', function (): void {
    $user = arcrpUser();
    $account = arcrpAccount($user);
    $seeded = arcrpSeedConflict($user, $account, EnrichmentConflictField::CounterpartyName, stored: 'NLPAYPAL ALBERT HEIJN', incoming: 'Albert Heijn');

    Event::fake([TransactionMutated::class]);

    /** @var ApplyReceiptConflictResolution $resolve */
    $resolve = app(ApplyReceiptConflictResolution::class);
    $resolve($user, ReceiptConflictChoice::PreferReceipt, $seeded['conflictId']);

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $seeded['tx']->id)->first();

    Event::assertDispatched(TransactionMutated::class, function (TransactionMutated $e) use ($seeded, $row): bool {
        return $e->transactionId === $seeded['tx']->id
            && ($e->dirtyFields['counterparty_name'] ?? null) === 'Albert Heijn'
            && ($e->dirtyFields['counterparty_normalized'] ?? null) === $row->counterparty_normalized
            && ($e->dirtyFields['fingerprint'] ?? null) === $row->fingerprint;
    });
});

it('announces nothing when the reader keeps the statement value', function (): void {
    $user = arcrpUser();
    $account = arcrpAccount($user);
    $seeded = arcrpSeedConflict($user, $account, EnrichmentConflictField::AmountMinor, stored: -5000, incoming: -4820);

    Event::fake([TransactionMutated::class]);

    /** @var ApplyReceiptConflictResolution $resolve */
    $resolve = app(ApplyReceiptConflictResolution::class);
    $resolve($user, ReceiptConflictChoice::PreferFirstWrite, $seeded['conflictId']);

    Event::assertNotDispatched(TransactionMutated::class);
});

// Every sibling transaction writer consults TransactionStatusQuery::locksEdits
// before it writes; this was the only one that did not, and the only one that
// rewrites the AMOUNT.
it('refuses to rewrite a reconciled transaction, and announces nothing for it', function (): void {
    $user = arcrpUser();
    $account = arcrpAccount($user);
    $seeded = arcrpSeedConflict(
        $user,
        $account,
        EnrichmentConflictField::AmountMinor,
        stored: -5000,
        incoming: -4820,
        status: ClearedStatus::Reconciled->value,
    );

    Event::fake([TransactionMutated::class]);

    /** @var ApplyReceiptConflictResolution $resolve */
    $resolve = app(ApplyReceiptConflictResolution::class);
    $count = $resolve($user, ReceiptConflictChoice::PreferReceipt, $seeded['conflictId']);

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $seeded['tx']->id)->first();

    expect((int) $row->amount_minor)->toBe(-5000);
    expect($row->fingerprint)->toBe($seeded['tx']->fingerprint);
    Event::assertNotDispatched(TransactionMutated::class);

    // The frozen value stands, and the backlog still clears: a conflict no
    // policy can ever resolve would raise the toast again on every render.
    expect($count)->toBe(1);
    expect(app(DatabaseManager::class)->connection()->table('pending_enrichment_conflicts')->where('id', $seeded['conflictId'])->count())->toBe(0);
});

// Each press answers its own conflict, so a frozen row's conflict clearing
// without a write must not stand between the reader and the next one.
it('resolves an unlocked row after a reconciled one in the same backlog cleared without a write', function (): void {
    $user = arcrpUser();
    $account = arcrpAccount($user);

    $frozen = arcrpSeedConflict($user, $account, EnrichmentConflictField::AmountMinor, stored: -5000, incoming: -4820, status: ClearedStatus::Reconciled->value);
    $open = arcrpSeedConflict($user, $account, EnrichmentConflictField::AmountMinor, stored: -5000, incoming: -3300);

    /** @var ApplyReceiptConflictResolution $resolve */
    $resolve = app(ApplyReceiptConflictResolution::class);
    expect($resolve($user, ReceiptConflictChoice::PreferReceipt, $frozen['conflictId']))->toBe(1);
    expect($resolve($user, ReceiptConflictChoice::PreferReceipt, $open['conflictId']))->toBe(1);

    $db = app(DatabaseManager::class)->connection();
    expect((int) $db->table('transactions')->where('id', $frozen['tx']->id)->value('amount_minor'))->toBe(-5000);
    expect((int) $db->table('transactions')->where('id', $open['tx']->id)->value('amount_minor'))->toBe(-3300);
    expect($db->table('pending_enrichment_conflicts')->where('user_id', $user->id)->count())->toBe(0);
});
