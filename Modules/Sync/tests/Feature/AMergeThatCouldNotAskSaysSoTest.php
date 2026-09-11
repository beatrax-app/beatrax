<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\Merge\CreateRowCollision;
use Modules\Sync\Internal\Merge\OpLogQuarantine;
use Modules\Sync\Internal\Merge\PeerRowAliases;
use Modules\Sync\Internal\Merge\RowOwnership;
use Modules\Sync\Internal\Merge\SelfReferenceDeferral;
use Modules\Sync\Internal\Merge\SplitCreateTail;
use Modules\Sync\Internal\Merge\SuppliedCreationTime;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

// Each of these swallows on purpose — raising into the middle of a replay costs
// the whole batch — and each swallowed in silence. The fallback every one of
// them returns reads as an ordinary answer downstream: plain registry order, no
// parent columns, no unique index, no stored row. So the merge went on and got a
// different result, with nothing anywhere saying a question had gone unanswered.

// The table an op names is registered by the time any of this runs —
// OpLogEntryVerifier quarantines an unregistered one as UnknownTable first — so
// the "table the schema cannot answer for" these comments described could never
// reach them. What can is a database that will not answer.
function mergeFaultDb(): DatabaseManager
{
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->andThrow(new RuntimeException('the schema would not answer'));
    $connection->shouldReceive('select')->andThrow(new RuntimeException('the schema would not answer'));
    $connection->shouldReceive('table')->andThrow(new RuntimeException('the database would not answer'));

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldReceive('connection')->andReturn($connection);

    /** @var DatabaseManager $db */
    return $db;
}

function mergeFaultLogger(string $needle): LoggerInterface
{
    $log = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

    $log->shouldReceive('warning')
        ->atLeast()->once()
        ->withArgs(fn (string $message): bool => str_contains($message, $needle));

    /** @var LoggerInterface $log */
    return $log;
}

// Throws only on the schema questions, and forwards the writes to the real
// connection — so the case can go on to check what the merge did with the
// answer it could not get, which is the half that matters.
function mergeFaultDbThatStillWrites(): DatabaseManager
{
    $real = mergeFaultRealDb()->connection();

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('select')->andThrow(new RuntimeException('the schema would not answer'));
    $connection->shouldReceive('getSchemaBuilder')->andThrow(new RuntimeException('the schema would not answer'));
    $connection->shouldReceive('table')->andReturnUsing(static fn (string $table) => $real->table($table));

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldReceive('connection')->andReturn($connection);

    /** @var DatabaseManager $db */
    return $db;
}

function mergeFaultRealDb(): DatabaseManager
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db;
}

// Plain registry order lists import_runs before transactions, which is the
// ordering this class exists to replace: every rebuild under it deleted a
// parent its children still referenced.
it('says so when the fallback is plain registry order', function (): void {
    $order = new CoveredTableOrder(
        mergeFaultDb(),
        new MergeRulesRegistry,
        mergeFaultLogger('plain registry order'),
    );

    expect($order->insertionOrder())->not->toBe([]);
});

it('says so when a table is treated as naming no parent', function (): void {
    $order = new CoveredTableOrder(
        mergeFaultDb(),
        new MergeRulesRegistry,
        mergeFaultLogger('naming no parent'),
    );

    expect($order->parentColumns('transactions'))->toBe([]);
});

it('says so when the unique indexes cannot be read', function (): void {
    $aliases = new PeerRowAliases(
        mergeFaultDbThatStillWrites(),
        new CoveredTableOrder(mergeFaultRealDb(), new MergeRulesRegistry),
        mergeFaultLogger('will not be matched'),
    );

    // remember() is the path that asks: no unique index means no natural key,
    // which means no twin, so no alias is written and the peer's row is
    // inserted beside the local one holding the same key.
    $aliases->remember('transactions', 'peer-device', 42, ['fingerprint' => 'abc'], 1);

    expect(mergeFaultRealDb()->connection()->table('op_log_row_aliases')->count())
        ->toBe(0, 'no alias was remembered, which is the cost the log line names');
});

it('says so when the row a create would land on cannot be read', function (): void {
    $collisions = new CreateRowCollision(
        mergeFaultDb(),
        app(SensitiveFieldRegistry::class),
        mergeFaultLogger('was not checked against it'),
        new RowOwnership(mergeFaultDb()),
    );

    expect($collisions->contradicts('transactions', 1, ['amount_minor' => 100], 1))->toBeFalse();
});

it('says so when it cannot tell whether the row is already here', function (): void {
    $tail = new SplitCreateTail(
        mergeFaultRealDb(),
        new RowOwnership(mergeFaultRealDb()),
        mergeFaultLogger('judged without it'),
    );

    expect($tail->rowIsHere('no_such_table_here', 1, 1))->toBeFalse();
});

// The quarantine row IS the record that the entry existed. Losing it loses the
// entry, and this is the only place that could ever have said so — which is why
// it is the one of these that goes out at error rather than warning.
it('says so at error when the quarantine row itself will not write', function (): void {
    mergeFaultRealDb()->connection()->statement('DROP TABLE op_log_quarantine');

    $log = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $log->shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'dropped with no record of it'));

    /** @var LoggerInterface $log */
    $quarantine = new OpLogQuarantine(mergeFaultRealDb(), $log);

    $quarantine->record(
        new OpLogEntry(
            table: 'transactions',
            pk: 9,
            field: 'amount_minor',
            value: '1',
            hlcL: 1,
            hlcC: 0,
            deviceId: 'peer-device',
            opType: OpType::Set,
            signature: 'sig',
            userId: 1,
        ),
        QuarantineReason::UnknownTable,
        '2026-01-01 00:00:00',
    );
});

function mergeFaultEntry(): OpLogEntry
{
    return new OpLogEntry(
        table: 'transactions',
        pk: 9,
        field: 'amount_minor',
        value: '1',
        hlcL: 1_760_000_000_000,
        hlcC: 0,
        deviceId: 'peer-device',
        opType: OpType::CreateRow,
        signature: 'sig',
        userId: 1,
    );
}

// False reads as "the column is not there", and the birth time the op carried
// is dropped rather than seeded — which is a row dated by when it travelled.
it('says so when the schema will not answer whether a column is there', function (): void {
    $seeder = new SuppliedCreationTime(
        mergeFaultDb(),
        mergeFaultLogger('left off the row'),
    );

    $seeded = $seeder->seed('transactions', ['id' => 1], ['created_at' => [mergeFaultEntry()]]);

    expect($seeded)->toBe(['id' => 1], 'nothing was seeded, which is the cost the log line names');
});

// Nothing else comes back for a deferred link: the deferral is resolved once,
// here, and a refusal leaves the row holding a null where its pair should be.
it('says so when a deferred self-reference is refused', function (): void {
    $deferral = new SelfReferenceDeferral(
        mergeFaultRealDb(),
        new RowOwnership(mergeFaultRealDb()),
        app(PeerRowAliases::class),
        mergeFaultLogger('keeps a null where its pair should be'),
    );

    mergeFaultRealDb()->connection()->statement(
        'CREATE TABLE merge_fault_pairs (id INTEGER PRIMARY KEY, user_id INTEGER, pair_id INTEGER)'
    );
    mergeFaultRealDb()->connection()->table('merge_fault_pairs')->insert(['id' => 1, 'user_id' => 1, 'pair_id' => null]);
    mergeFaultRealDb()->connection()->table('merge_fault_pairs')->insert(['id' => 2, 'user_id' => 1, 'pair_id' => null]);

    // A column the table does not have: the update is refused exactly as a
    // constraint would refuse it, and the row keeps its null either way.
    $deferral->apply([[
        'table' => 'merge_fault_pairs',
        'pk' => 1,
        'deviceId' => 'merge-fault-device',
        'values' => ['no_such_column' => 2],
    ]], 1);
});

// The create then travels with neither timestamp, and a null created_at is
// swept by no retention pass on the peer that receives it.
it('says so when a create is announced without its timestamps', function (): void {
    // Bound before the writer is built, because the factory hands it the
    // logger the container answers with at that moment.
    app()->instance(LoggerInterface::class, mergeFaultLogger('announced without its timestamps'));

    $keypair = sodium_crypto_sign_keypair();

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'merge-fault-device',
        'userId' => 1,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    $writer->writeCreateRow('no_such_table_here', 1, ['name' => 'x']);

    $created = mergeFaultRealDb()->connection()->table('op_log_entries')
        ->where('table_name', 'no_such_table_here')
        ->pluck('field')
        ->all();

    expect($created)->toBe(['name'], 'the create travelled with the one column it was given and neither timestamp');
});

// Null reads as "no stored row", so the tail fills nothing and the columns the
// second half of a split create carried are never written.
it('says so when the tail of a split create cannot be read', function (): void {
    $tail = new SplitCreateTail(
        mergeFaultRealDb(),
        new RowOwnership(mergeFaultRealDb()),
        mergeFaultLogger('was not filled in'),
    );

    $tail->fill('no_such_table_here', 1, ['name' => 'x'], 1);
});
