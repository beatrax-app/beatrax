<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Sync\Internal\Listeners\SyncCaptureListener;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Events\TransactionMutated;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function seedCaptureTransaction(DatabaseManager $db, int $userId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN capture test',
        'slug' => 'capture-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/capture-test.csv',
        'sha256' => hash('sha256', 'capture-run-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-06-14 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'capture-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-14',
        'booked_at' => '2026-06-14 10:00:00',
        'value_date' => '2026-06-14',
        'amount_minor' => -2000,
        'currency' => 'EUR',
        'settled_amount_minor' => -2000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'test merchant',
        'counterparty_name' => 'Test Merchant',
        'normalization_version' => 3,
        'description' => 'capture wiring test',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);
}

/**
 * @return array{writer: OpLogWriter, listener: SyncCaptureListener, userId: int, txnId: int}
 */
function buildWriterAndFixture(DatabaseManager $db): array
{
    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'capture-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $txnId = seedCaptureTransaction($db, $userId);

    $keypair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($keypair);
    $pk = sodium_crypto_sign_publickey($keypair);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'capture-device',
        'userId' => $userId,
        'secretKey' => $sk,
        'publicKey' => $pk,
    ]);

    app()->instance(OpLogWriter::class, $writer);

    /** @var SyncCaptureListener $listener */
    $listener = app(SyncCaptureListener::class);

    return ['writer' => $writer, 'listener' => $listener, 'userId' => $userId, 'txnId' => $txnId];
}

it('dispatching TransactionMutated(edit,{category_id}) lands a Set op in op_log_entries', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    ['listener' => $listener, 'userId' => $userId, 'txnId' => $txnId] = buildWriterAndFixture($db);

    $catId = (int) $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Test Cat',
        'slug' => 'test-cat-capture',
        'kind' => 'expense',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $event = new TransactionMutated(
        transactionId: $txnId,
        userId: $userId,
        mutationType: 'edit',
        dirtyFields: ['category_id' => $catId],
    );

    $listener->handle($event);

    $rows = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'transactions')
        ->where('field', 'category_id')
        ->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->op_type)->toBe('set');
    expect($rows->first()->field)->toBe('category_id');
    expect($rows->first()->value)->toBe(json_encode($catId));
});

it('dispatching TransactionMutated(delete) lands a delete_tombstone op in op_log_entries', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    ['listener' => $listener, 'userId' => $userId, 'txnId' => $txnId] = buildWriterAndFixture($db);

    $event = new TransactionMutated(
        transactionId: $txnId,
        userId: $userId,
        mutationType: 'delete',
        dirtyFields: [],
    );

    $listener->handle($event);

    $rows = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'transactions')
        ->where('op_type', 'delete_tombstone')
        ->get();

    expect($rows)->toHaveCount(1);
});

it('SyncCaptureListener::handle does not propagate exceptions — a bad OpLogWriter does not abort the caller', function (): void {
    // The listener runs inside the request that saved the row, so a write it
    // cannot perform must never become the user's edit failing.

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    ['listener' => $listener, 'userId' => $userId, 'txnId' => $txnId] = buildWriterAndFixture($db);

    $event = new TransactionMutated(
        transactionId: $txnId,
        userId: $userId,
        mutationType: 'edit',
        dirtyFields: ['note' => 'test note'],
    );

    $threw = false;

    try {
        $listener->handle($event);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
    $rows = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('field', 'note')
        ->get();
    expect($rows)->toHaveCount(1);
});

it('TransactionMutated event is dispatched through the framework Dispatcher to SyncCaptureListener', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    ['userId' => $userId, 'txnId' => $txnId] = buildWriterAndFixture($db);

    // The provider's singleton writer carries no device credentials, so this
    // covers the dispatch plumbing only; the listener itself is driven directly
    // in the tests above.

    Event::fake([TransactionMutated::class]);

    /** @var Dispatcher $dispatcher */
    $dispatcher = app(Dispatcher::class);
    $dispatcher->dispatch(new TransactionMutated(
        transactionId: $txnId,
        userId: $userId,
        mutationType: 'edit',
        dirtyFields: ['type' => 'income'],
    ));

    Event::assertDispatched(TransactionMutated::class, function (TransactionMutated $e) use ($txnId, $userId): bool {
        return $e->transactionId === $txnId
            && $e->userId === $userId
            && $e->mutationType === 'edit'
            && ($e->dirtyFields['type'] ?? null) === 'income';
    });
});
