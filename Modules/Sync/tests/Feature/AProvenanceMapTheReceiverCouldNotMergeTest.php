<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogBackfiller;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

// transactions.field_provenance and counterparties.metadata merge as a key
// union, so an op has to carry the MAP. The backfill reads whole rows back
// through the query builder, which hands over the column's stored JSON TEXT --
// and writeCreateRow() encoded that string, so the receiver decoded a string
// where the strategy requires an object and quarantined the whole create.

const PROVENANCE_DESKTOP = 'the-desktop-that-announced-the-row';

test('the backfill puts a key-union column on the wire as a map, not as the text the column stores', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $seeded = provenanceSeedDesktop($db);

    provenanceBackfill($db, $seeded['userId'], provenanceKeypair());

    $values = $db->connection()->table('op_log_entries')
        ->where('user_id', $seeded['userId'])
        ->whereIn('field', ['field_provenance', 'metadata'])
        ->where('op_type', OpType::CreateRow->value)
        ->pluck('value');

    expect($values)->toHaveCount(2);

    foreach ($values as $value) {
        expect(json_decode((string) $value, true))->toBeArray();
    }
});

test('a create carrying a provenance map merges on the device that already holds the row', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $keypair = provenanceKeypair();
    $seeded = provenanceSeedDesktop($db);

    $phone = provenancePhone($db);
    provenanceSeedPhone($db, $phone, $seeded);

    provenanceBackfill($db, $seeded['userId'], $keypair);

    provenanceReplay($db, $phone, provenanceEntries($db, $seeded['userId']), $seeded['userId'], $keypair);

    expect($phone->table('op_log_quarantine')->pluck('reason')->all())->toBe([])
        ->and($phone->table('transactions')->where('id', $seeded['transactionId'])->value('field_provenance'))
        ->toBe('{"note":"manual"}')
        ->and($phone->table('counterparties')->where('id', $seeded['counterpartyId'])->value('metadata'))
        ->toBe('{"matched_keyword":"BELASTINGDIENST"}');
});

test('a map an older build wrapped in a string still merges, because its op is signed and cannot be rewritten', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $keypair = provenanceKeypair();
    $seeded = provenanceSeedDesktop($db);

    $phone = provenancePhone($db);
    provenanceSeedPhone($db, $phone, $seeded);

    $signer = new DeviceKeySigner;

    $entry = new OpLogEntry(
        table: 'transactions',
        pk: $seeded['transactionId'],
        field: 'field_provenance',
        // The shape a build before this one signed: json_encode() over the
        // column's stored text rather than over the map inside it.
        value: json_encode('{"note":"manual"}', JSON_THROW_ON_ERROR),
        hlcL: 1789073094376,
        hlcC: 2,
        deviceId: PROVENANCE_DESKTOP,
        opType: OpType::Set,
        signature: '',
        userId: $seeded['userId'],
    );

    $signed = new OpLogEntry(
        table: $entry->table,
        pk: $entry->pk,
        field: $entry->field,
        value: $entry->value,
        hlcL: $entry->hlcL,
        hlcC: $entry->hlcC,
        deviceId: $entry->deviceId,
        opType: $entry->opType,
        signature: $signer->sign($entry->signingPayload(), sodium_crypto_sign_secretkey($keypair)),
        userId: $entry->userId,
    );

    provenanceReplay($db, $phone, [$signed], $seeded['userId'], $keypair);

    expect($phone->table('op_log_quarantine')->pluck('reason')->all())->toBe([])
        ->and($phone->table('transactions')->where('id', $seeded['transactionId'])->value('field_provenance'))
        ->toBe('{"note":"manual"}');
});

test('a refusal the merge layer swallowed says which field and which failure, and quotes no value', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $keypair = provenanceKeypair();
    $seeded = provenanceSeedDesktop($db);

    $phone = provenancePhone($db);
    provenanceSeedPhone($db, $phone, $seeded);

    $logger = new class extends AbstractLogger
    {
        /** @var list<array{message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['message' => (string) $message, 'context' => $context];
        }
    };

    app()->instance(LoggerInterface::class, $logger);

    // Neither op can be merged even after the unwrap: a bare string is not a
    // map however many times it is decoded. One is a Set onto the row the phone
    // holds, one a create for a row it does not.
    provenanceReplay($db, $phone, [
        provenanceSignedEntry($keypair, $seeded['userId'], $seeded['transactionId'], OpType::Set, '"manual"', 1789073094376),
        provenanceSignedEntry($keypair, $seeded['userId'], $seeded['transactionId'] + 900, OpType::CreateRow, '"manual"', 1789073094377),
    ], $seeded['userId'], $keypair);

    $refusals = array_values(array_filter(
        $logger->records,
        static fn (array $record): bool => str_contains($record['message'], 'could not be merged into the column it names'),
    ));

    expect($refusals)->toHaveCount(2);

    foreach ($refusals as $refusal) {
        expect($refusal['context']['field'] ?? null)->toBe('field_provenance')
            ->and($refusal['context']['table'] ?? null)->toBe('transactions')
            ->and($refusal['context']['reason'] ?? null)->toBe(UnexpectedValueException::class)
            ->and($refusal['context'])->not->toHaveKey('message')
            ->and($refusal['context'])->not->toHaveKey('raw_value');
    }
});

/**
 * @return array{userId: int, accountId: int, importRunId: int, transactionId: int, counterpartyId: int}
 */
function provenanceSeedDesktop(DatabaseManager $db): array
{
    $connection = $db->connection();

    $userId = (int) $connection->table('users')->insertGetId([
        'username' => 'provenance-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $counterpartyId = (int) $connection->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'government',
        'slug' => 'belastingdienst',
        'display_name' => 'Belastingdienst',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $connection->table('counterparties')->where('id', $counterpartyId)->update([
        'metadata' => json_encode(['matched_keyword' => 'BELASTINGDIENST'], JSON_THROW_ON_ERROR),
    ]);

    $accountId = (int) $connection->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN',
        'slug' => 'provenance-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.substr(bin2hex(random_bytes(8)), 0, 10),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $importRunId = (int) $connection->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/provenance.csv',
        'sha256' => hash('sha256', 'provenance'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-06-14 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $transactionId = (int) $connection->table('transactions')->insertGetId(
        provenanceTransactionRow($userId, $accountId, $importRunId, $counterpartyId)
    );

    app(FieldProvenanceWriter::class)->stamp($userId, $transactionId, ['note' => 'manual']);

    return [
        'userId' => $userId,
        'accountId' => $accountId,
        'importRunId' => $importRunId,
        'transactionId' => $transactionId,
        'counterpartyId' => $counterpartyId,
    ];
}

/**
 * @return array<string, mixed>
 */
function provenanceTransactionRow(int $userId, int $accountId, int $importRunId, int $counterpartyId): array
{
    return [
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $importRunId,
        'counterparty_id' => $counterpartyId,
        'fingerprint' => hash('sha256', 'provenance-fingerprint'),
        'fingerprint_version' => 3,
        'posted_at' => '2026-06-14',
        'booked_at' => '2026-06-14 00:00:00',
        'value_date' => '2026-06-14',
        'amount_minor' => -125000,
        'currency' => 'EUR',
        'settled_amount_minor' => -125000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'belastingdienst',
        'counterparty_name' => 'BELASTINGDIENST',
        'normalization_version' => 1,
        'description' => 'Aanslag inkomstenbelasting',
        'note' => 'paid from the joint account',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ];
}

// A second SQLite database carrying the same schema, so the receiving side is
// genuinely another device rather than the sending row read back. Built from
// the desktop's own sqlite_master rather than by running the migrator, which
// leaves the suite's RefreshDatabase connection alone.
function provenancePhone(DatabaseManager $db): Connection
{
    config(['database.connections.phone' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);

    $db->purge('phone');
    $phone = $db->connection('phone');

    $objects = $db->connection()->select(
        "select name, sql from sqlite_master where sql is not null and name not like 'sqlite_%' order by (sql like 'CREATE VIRTUAL TABLE%') desc"
    );

    $present = [];

    foreach ($objects as $object) {
        if (isset($present[(string) $object->name])) {
            continue;
        }

        $phone->statement((string) $object->sql);

        // An FTS5 virtual table brings its own shadow tables, which are then
        // named by sqlite_master rows of their own.
        foreach ($phone->select("select name from sqlite_master where name not like 'sqlite_%'") as $created) {
            $present[(string) $created->name] = true;
        }
    }

    return $phone;
}

/**
 * @param  array{userId: int, accountId: int, importRunId: int, transactionId: int, counterpartyId: int}  $seeded
 */
function provenanceSeedPhone(DatabaseManager $db, Connection $phone, array $seeded): void
{
    $desktop = $db->connection();

    foreach (['users', 'accounts', 'import_runs', 'counterparties'] as $table) {
        foreach ($desktop->table($table)->get() as $row) {
            $phone->table($table)->insert((array) $row);
        }
    }

    // Every column the desktop holds except the stamp, which is what this
    // device is waiting to be told.
    $phone->table('counterparties')->where('id', $seeded['counterpartyId'])->update(['metadata' => null]);

    $phone->table('transactions')->insert([
        'id' => $seeded['transactionId'],
        ...provenanceTransactionRow($seeded['userId'], $seeded['accountId'], $seeded['importRunId'], $seeded['counterpartyId']),
    ]);
}

/**
 * @return array{0: string, 1: string}
 */
function provenanceKeypair(): string
{
    static $keypair = null;

    return $keypair ??= sodium_crypto_sign_keypair();
}

function provenanceBackfill(DatabaseManager $db, int $userId, string $keypair): void
{
    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => PROVENANCE_DESKTOP,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    app(OpLogBackfiller::class)->backfill($userId, $writer);
}

/**
 * @return list<OpLogEntry>
 */
function provenanceEntries(DatabaseManager $db, int $userId): array
{
    $entries = [];

    $rows = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->orderBy('hlc_l')
        ->orderBy('hlc_c')
        ->get();

    foreach ($rows as $row) {
        $entries[] = new OpLogEntry(
            table: (string) $row->table_name,
            pk: is_numeric($row->pk) ? (int) $row->pk : (string) $row->pk,
            field: (string) $row->field,
            value: $row->value === null ? null : (string) $row->value,
            hlcL: (int) $row->hlc_l,
            hlcC: (int) $row->hlc_c,
            deviceId: (string) $row->device_id,
            opType: OpType::from((string) $row->op_type),
            signature: (string) $row->signature,
            userId: $userId,
            gdkEpoch: $row->gdk_epoch === null ? null : (int) $row->gdk_epoch,
        );
    }

    return $entries;
}

/**
 * @param  list<OpLogEntry>  $entries
 */
function provenanceReplay(DatabaseManager $db, Connection $phone, array $entries, int $userId, string $keypair): void
{
    $replayer = new OpLogReplayer(
        db: $db,
        deviceKeys: [PROVENANCE_DESKTOP => bin2hex(sodium_crypto_sign_publickey($keypair))],
        rules: new MergeRulesRegistry,
        deviceKeysUserId: $userId,
    );

    $previous = $db->getDefaultConnection();
    $db->setDefaultConnection($phone->getName());

    try {
        $replayer->replay($entries, $userId);
    } finally {
        $db->setDefaultConnection($previous);
    }
}

function provenanceSignedEntry(string $keypair, int $userId, int $pk, OpType $opType, string $value, int $hlcL): OpLogEntry
{
    $entry = new OpLogEntry(
        table: 'transactions',
        pk: $pk,
        field: 'field_provenance',
        value: $value,
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: PROVENANCE_DESKTOP,
        opType: $opType,
        signature: '',
        userId: $userId,
    );

    return new OpLogEntry(
        table: $entry->table,
        pk: $entry->pk,
        field: $entry->field,
        value: $entry->value,
        hlcL: $entry->hlcL,
        hlcC: $entry->hlcC,
        deviceId: $entry->deviceId,
        opType: $entry->opType,
        signature: (new DeviceKeySigner)->sign($entry->signingPayload(), sodium_crypto_sign_secretkey($keypair)),
        userId: $entry->userId,
    );
}
