<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Internal\Encryption\PreMigrationSnapshot;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// The enable-time sweep and the rollback restore both run inside one
// transaction, so a statement per ledger row holds the single SQLite writer
// lock for the whole pass and a whole-table read holds the ledger twice over.
// These fixtures are sized past one CHUNK_SIZE so the batching is observable.
const BATCHED_TRANSACTIONS = 520;

const BATCHED_COUNTERPARTIES = 210;

const BATCHED_NOTIFICATIONS = 260;

const BATCHED_OP_LOG = 240;

function batchedUser(string $tag): User
{
    return User::query()->create([
        'username' => 'batched-'.$tag.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function batchedLedgerScaffold(User $user, string $tag): array
{
    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'asn-'.$tag.'-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456782',
        'default_currency' => 'EUR',
    ]);

    $importRun = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/batched-'.$tag.'.csv',
        'sha256' => hash('sha256', $tag),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);

    return [$account, $importRun];
}

/**
 * @return array<string, mixed>
 */
function batchedTransactionRow(User $user, Account $account, ImportRun $importRun, int $index, int $payloadBytes = 0): array
{
    $payload = $payloadBytes > 0
        ? json_encode(['blob' => str_repeat('p', $payloadBytes), 'seq' => $index], JSON_THROW_ON_ERROR)
        : json_encode(['iban' => 'NL91ABNA'.str_pad((string) $index, 10, '0', STR_PAD_LEFT), 'seq' => $index], JSON_THROW_ON_ERROR);

    return [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-01',
        'booked_at' => '2026-07-01 10:00:00',
        'value_date' => '2026-07-01',
        'amount_minor' => -1000 - $index,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000 - $index,
        'settled_currency' => 'EUR',
        'description' => "Albert Heijn 1234 pinbetaling {$index}",
        'note' => "handmatige notitie {$index}",
        'counterparty_name' => "Albert Heijn Filiaal {$index}",
        'counterparty_iban' => 'NL91ABNA'.str_pad((string) $index, 10, '0', STR_PAD_LEFT),
        'raw_payload' => $payload,
        'counterparty_normalized' => 'albert heijn '.$index,
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $importRun->id,
        'source_row_index' => $index,
        'source_ref' => "unrelated-sentinel-{$index}",
        'fingerprint' => hash('sha256', "seed-{$index}"),
        'fingerprint_version' => 1,
        'status' => 'cleared',
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

function batchedSeedLedger(DatabaseManager $db, User $user, Account $account, ImportRun $importRun): void
{
    $connection = $db->connection();

    $rows = [];
    for ($i = 0; $i < BATCHED_TRANSACTIONS; $i++) {
        $rows[] = batchedTransactionRow($user, $account, $importRun, $i);
        if (count($rows) === 100) {
            $connection->table('transactions')->insert($rows);
            $rows = [];
        }
    }

    // Five rows whose sensitive columns are all empty. The sweep builds no
    // write for them at all, so a batched statement must leave them exactly as
    // they are rather than folding them into its own row list.
    for ($i = BATCHED_TRANSACTIONS; $i < BATCHED_TRANSACTIONS + 5; $i++) {
        $row = batchedTransactionRow($user, $account, $importRun, $i);
        $row['description'] = null;
        $row['note'] = null;
        $row['counterparty_name'] = null;
        $row['counterparty_iban'] = null;
        $row['raw_payload'] = null;
        $rows[] = $row;
    }
    $connection->table('transactions')->insert($rows);

    $rows = [];
    for ($i = 0; $i < BATCHED_COUNTERPARTIES; $i++) {
        $rows[] = [
            'user_id' => $user->id,
            'type' => 'merchant',
            'slug' => "merchant-{$i}",
            'display_name' => "Zilveren Kruis {$i}",
            'iban' => 'NL57ASNB'.str_pad((string) $i, 10, '0', STR_PAD_LEFT),
            'merchant_name' => "ZILVEREN KRUIS ACHMEA {$i}",
            'metadata' => json_encode(['sentinel' => "untouched-{$i}"], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (count($rows) === 100) {
            $connection->table('counterparties')->insert($rows);
            $rows = [];
        }
    }
    $connection->table('counterparties')->insert($rows);

    $rows = [];
    for ($i = 0; $i < BATCHED_NOTIFICATIONS; $i++) {
        $rows[] = [
            'id' => hash('sha256', "notification-{$user->id}-{$i}"),
            'user_id' => $user->id,
            'state' => 'open',
            'title' => "Zilveren Kruis premie {$i} staat klaar",
            'body' => "Je zorgverzekeraar schrijft EUR 142,{$i} af op de 24e.",
            'params' => json_encode(['merchant' => "Zilveren Kruis {$i}"], JSON_THROW_ON_ERROR),
            'trigger_type' => 'bill_due',
            'created_at' => '2026-07-01 09:00:00',
            'updated_at' => '2026-07-01 09:00:00',
        ];
        if (count($rows) === 100) {
            $connection->table('notifications')->insert($rows);
            $rows = [];
        }
    }
    $connection->table('notifications')->insert($rows);

    $rows = [];
    for ($i = 0; $i < BATCHED_OP_LOG; $i++) {
        $rows[] = [
            'user_id' => $user->id,
            'device_id' => 'device-batched',
            'table_name' => 'transactions',
            'pk' => (string) ($i + 1),
            'field' => 'description',
            'op_type' => 'set',
            'value' => json_encode("Albert Heijn 1234 pinbetaling {$i}", JSON_THROW_ON_ERROR),
            'hlc_l' => 1_700_000_000_000 + $i,
            'hlc_c' => 0,
            'signature' => "signature-sentinel-{$i}",
            'recorded_at' => now(),
        ];
        if (count($rows) === 100) {
            $connection->table('op_log_entries')->insert($rows);
            $rows = [];
        }
    }

    // A field the registry does not list. The sweep must leave both its value
    // and its null epoch alone, which is what proves a batched statement never
    // widens past the rows it was handed.
    for ($i = 0; $i < 5; $i++) {
        $rows[] = [
            'user_id' => $user->id,
            'device_id' => 'device-batched',
            'table_name' => 'accounts',
            'pk' => (string) ($i + 1),
            'field' => 'name',
            'op_type' => 'set',
            'value' => json_encode("ASN Betaalrekening {$i}", JSON_THROW_ON_ERROR),
            'hlc_l' => 1_800_000_000_000 + $i,
            'hlc_c' => 0,
            'signature' => "signature-insensitive-{$i}",
            'recorded_at' => now(),
        ];
    }
    $connection->table('op_log_entries')->insert($rows);
}

/**
 * @return array<int|string, array<string, mixed>>
 */
function batchedColumnMap(DatabaseManager $db, string $table, int $userId, array $columns): array
{
    $map = [];
    foreach ($db->connection()->table($table)->where('user_id', $userId)->orderBy('id')->get() as $row) {
        $values = [];
        foreach ($columns as $column) {
            $values[$column] = $row->{$column};
        }
        $map[$row->id] = $values;
    }

    return $map;
}

function batchedRecordingCache(): Repository
{
    return new class(new ArrayStore) extends Repository
    {
        /** @var list<int> */
        public array $progressWrites = [];

        public function put($key, $value, $ttl = null)
        {
            if (is_string($key) && str_starts_with($key, 'encryption-migration-progress:') && is_int($value)) {
                $this->progressWrites[] = $value;
            }

            return parent::put($key, $value, $ttl);
        }
    };
}

function batchedMigrationService(DatabaseManager $db, Repository $cache): EncryptionMigrationService
{
    return new EncryptionMigrationService(
        $db,
        app(PreMigrationSnapshot::class),
        app(AppLockKeyService::class),
        app(Clock::class),
        app(Container::class),
        $cache,
    );
}

// A file encryptor that only copies, so a test can read the staged payload the
// real one consumes. The snapshot's own confidentiality is proven elsewhere;
// what is under test here is the shape and the memory the writer holds.
function batchedCopyingEncryptor(): FileEncryptor
{
    return new class implements FileEncryptor
    {
        public function encrypt(string $plainPath, string $encPath, string $passphrase): void
        {
            copy($plainPath, $encPath);
        }

        public function encryptWithKey(string $plainPath, string $encPath, string $key): void
        {
            copy($plainPath, $encPath);
        }

        public function decrypt(string $encPath, string $plainPath, string $passphrase): void
        {
            copy($encPath, $plainPath);
        }

        public function kdfParams(string $encPath): array
        {
            return [0, 0];
        }
    };
}

it('sweeps a chunk in a handful of batched statements rather than one per row, and every swept value still decrypts to exactly the plaintext it replaced', function (): void {
    $user = batchedUser('sweep');
    [$account, $importRun] = batchedLedgerScaffold($user, 'sweep');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    batchedSeedLedger($db, $user, $account, $importRun);

    $transactionsBefore = batchedColumnMap($db, 'transactions', $user->id, ['note', 'description', 'counterparty_name', 'counterparty_iban', 'raw_payload', 'source_ref', 'status', 'amount_minor', 'currency']);
    $counterpartiesBefore = batchedColumnMap($db, 'counterparties', $user->id, ['display_name', 'merchant_name', 'iban', 'slug', 'metadata', 'type']);
    $notificationsBefore = batchedColumnMap($db, 'notifications', $user->id, ['title', 'body', 'params', 'trigger_type', 'state', 'created_at']);
    $opLogBefore = batchedColumnMap($db, 'op_log_entries', $user->id, ['value', 'gdk_epoch', 'signature', 'hlc_l', 'field']);

    $statements = [];
    $db->connection()->listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    $cache = batchedRecordingCache();
    batchedMigrationService($db, $cache)->migrate($user, $session);

    $sweepWrites = static fn (string $table, string $column): int => count(array_filter(
        $statements,
        static fn (string $sql): bool => str_starts_with($sql, "update \"{$table}\" set") && str_contains($sql, "\"{$column}\" ="),
    ));

    // Without the batching each of these equals the row count of its table.
    expect($sweepWrites('transactions', 'description'))->toBeLessThan(20)->toBeGreaterThan(0);
    expect($sweepWrites('counterparties', 'display_name'))->toBeLessThan(10)->toBeGreaterThan(0);
    expect($sweepWrites('notifications', 'title'))->toBeLessThan(10)->toBeGreaterThan(0);
    expect($sweepWrites('op_log_entries', 'gdk_epoch'))->toBeLessThan(10)->toBeGreaterThan(0);

    $batched = array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'case "id" when'));
    expect($batched)->not->toBeEmpty();

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $transactionsAfter = batchedColumnMap($db, 'transactions', $user->id, ['note', 'description', 'counterparty_name', 'counterparty_iban', 'raw_payload', 'source_ref', 'status', 'amount_minor', 'currency']);
    expect(array_keys($transactionsAfter))->toBe(array_keys($transactionsBefore));

    $recovered = 0;
    foreach ($transactionsAfter as $id => $after) {
        $before = $transactionsBefore[$id];

        foreach (['source_ref', 'status', 'amount_minor', 'currency'] as $untouched) {
            expect($after[$untouched])->toBe($before[$untouched]);
        }

        foreach (['note', 'description', 'counterparty_name', 'counterparty_iban', 'raw_payload'] as $column) {
            if ($before[$column] === null) {
                expect($after[$column])->toBeNull();

                continue;
            }

            expect($after[$column])->not->toBe($before[$column]);
            expect($codec->decryptValue('transactions', $column, (string) $after[$column], (int) $user->id, $session)['value'])
                ->toBe($before[$column]);
            $recovered++;
        }
    }
    expect($recovered)->toBe(BATCHED_TRANSACTIONS * 5);

    $counterpartiesAfter = batchedColumnMap($db, 'counterparties', $user->id, ['display_name', 'merchant_name', 'iban', 'slug', 'metadata', 'type']);
    foreach ($counterpartiesAfter as $id => $after) {
        $before = $counterpartiesBefore[$id];
        foreach (['slug', 'metadata', 'type'] as $untouched) {
            expect($after[$untouched])->toBe($before[$untouched]);
        }
        foreach (['display_name', 'merchant_name', 'iban'] as $column) {
            expect($codec->decryptValue('counterparties', $column, (string) $after[$column], (int) $user->id, $session)['value'])
                ->toBe($before[$column]);
        }
    }

    $notificationsAfter = batchedColumnMap($db, 'notifications', $user->id, ['title', 'body', 'params', 'trigger_type', 'state', 'created_at']);
    foreach ($notificationsAfter as $id => $after) {
        $before = $notificationsBefore[$id];
        foreach (['state', 'created_at'] as $untouched) {
            expect($after[$untouched])->toBe($before[$untouched]);
        }
        foreach (['title', 'body', 'params', 'trigger_type'] as $column) {
            expect($codec->decryptValue('notifications', $column, (string) $after[$column], (int) $user->id, $session)['value'])
                ->toBe($before[$column]);
        }
    }

    $opLogAfter = batchedColumnMap($db, 'op_log_entries', $user->id, ['value', 'gdk_epoch', 'signature', 'hlc_l', 'field']);
    $epochs = [];
    foreach ($opLogAfter as $id => $after) {
        $before = $opLogBefore[$id];
        expect($after['signature'])->toBe($before['signature']);
        expect($after['hlc_l'])->toBe($before['hlc_l']);

        if ($before['field'] === 'name') {
            expect($after['value'])->toBe($before['value']);
            expect($after['gdk_epoch'])->toBeNull();

            continue;
        }

        expect($after['value'])->not->toBe($before['value']);
        expect($after['gdk_epoch'])->not->toBeNull();
        $epochs[(int) $after['gdk_epoch']] = true;
    }
    expect(count($epochs))->toBe(1);
});

it('writes the migration progress once per chunk, monotonically, and still lands on 100', function (): void {
    $user = batchedUser('progress');
    [$account, $importRun] = batchedLedgerScaffold($user, 'progress');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    batchedSeedLedger($db, $user, $account, $importRun);

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    $cache = batchedRecordingCache();
    $migration = batchedMigrationService($db, $cache);
    $migration->migrate($user, $session);

    $rows = BATCHED_TRANSACTIONS + 5 + BATCHED_COUNTERPARTIES + BATCHED_NOTIFICATIONS + BATCHED_OP_LOG + 5;

    // One per chunk plus the opening zero and the closing hundred; a write per
    // row would be $rows of them, each one a file or a DB round trip inside the
    // migration transaction.
    expect(count($cache->progressWrites))->toBeLessThan(12);
    expect(count($cache->progressWrites))->toBeGreaterThan(3);
    expect($rows)->toBeGreaterThan(1000);

    expect($cache->progressWrites[0])->toBe(0);
    expect(end($cache->progressWrites))->toBe(100);

    $previous = -1;
    foreach ($cache->progressWrites as $percent) {
        expect($percent)->toBeGreaterThanOrEqual($previous);
        $previous = $percent;
    }

    // The poller has to see the pass move, not just its two endpoints.
    $midway = array_slice($cache->progressWrites, 1, -1);
    expect(max($midway))->toBeGreaterThan(0);
    expect(max($midway))->toBeLessThan(100);

    expect($migration->progress((int) $user->id))->toBe(100);
});

it('streams the snapshot to disk a line per row instead of holding the whole ledger and a JSON copy of it at once', function (): void {
    $user = batchedUser('stream');
    [$account, $importRun] = batchedLedgerScaffold($user, 'stream');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $connection = $db->connection();

    $payloadBytes = 8_000;
    $rows = [];
    for ($i = 0; $i < 1_200; $i++) {
        $rows[] = batchedTransactionRow($user, $account, $importRun, $i, $payloadBytes);
        if (count($rows) === 50) {
            $connection->table('transactions')->insert($rows);
            $rows = [];
        }
    }

    $snapshot = new PreMigrationSnapshot(batchedCopyingEncryptor(), app(Clock::class));

    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();

    $path = $snapshot->takeSnapshot((int) $user->id, $connection, str_repeat("\x2a", 32));

    $peakGrowth = memory_get_peak_usage() - $before;

    // 1200 rows of 8 KB is ~9.6 MB of payload. Materialising every row and then
    // json_encode-ing the lot holds it at least twice over; a streamed writer
    // never holds more than one chunk.
    expect($peakGrowth)->toBeLessThan(12 * 1024 * 1024);

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expect(count($lines))->toBe(1_200);

    $first = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
    expect($first[0])->toBe('transactions');
    expect(array_keys($first[1]))->toBe(['id', 'note', 'description', 'counterparty_name', 'counterparty_iban', 'raw_payload']);

    $seen = [];
    foreach ($lines as $line) {
        $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $seen[$entry[1]['id']] = $entry[1]['description'];
    }

    $expected = [];
    foreach ($connection->table('transactions')->where('user_id', $user->id)->orderBy('id')->get(['id', 'description']) as $row) {
        $expected[$row->id] = $row->description;
    }
    expect($seen)->toBe($expected);

    // The reader has to stream in lockstep: decoding the whole payload into one
    // array puts the ledger back in memory on the rollback path, which is the
    // path already running under whatever exhausted the pass in the first place.
    $connection->table('transactions')->where('user_id', $user->id)->update(['description' => 'CIPHERTEXT']);

    gc_collect_cycles();
    memory_reset_peak_usage();
    $beforeRestore = memory_get_usage();

    $snapshot->restoreFromSnapshot($path, str_repeat("\x2a", 32), $connection);

    expect(memory_get_peak_usage() - $beforeRestore)->toBeLessThan(12 * 1024 * 1024);

    $restored = [];
    foreach ($connection->table('transactions')->where('user_id', $user->id)->orderBy('id')->get(['id', 'description']) as $row) {
        $restored[$row->id] = $row->description;
    }
    expect($restored)->toBe($expected);

    @unlink($path);
});

it('restores every snapshotted column to exactly its snapshotted value in batched statements, and touches no row the snapshot does not name', function (): void {
    $user = batchedUser('restore');
    $other = batchedUser('bystander');
    [$account, $importRun] = batchedLedgerScaffold($user, 'restore');
    [$otherAccount, $otherImportRun] = batchedLedgerScaffold($other, 'bystander');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $connection = $db->connection();
    batchedSeedLedger($db, $user, $account, $importRun);

    $connection->table('transactions')->insert(batchedTransactionRow($other, $otherAccount, $otherImportRun, 99_001));
    $connection->table('notifications')->insert([
        'id' => hash('sha256', "notification-{$other->id}-bystander"),
        'user_id' => $other->id,
        'state' => 'open',
        'title' => 'bystander title',
        'body' => 'bystander body',
        'params' => null,
        'trigger_type' => 'bill_due',
        'created_at' => '2026-07-01 09:00:00',
        'updated_at' => '2026-07-01 09:00:00',
    ]);

    $snapshotColumns = ['note', 'description', 'counterparty_name', 'counterparty_iban', 'raw_payload'];
    $before = batchedColumnMap($db, 'transactions', $user->id, array_merge($snapshotColumns, ['source_ref', 'status', 'amount_minor']));
    $notificationsBefore = batchedColumnMap($db, 'notifications', $user->id, ['title', 'body', 'params', 'trigger_type', 'state']);
    $counterpartiesBefore = batchedColumnMap($db, 'counterparties', $user->id, ['display_name', 'merchant_name', 'iban', 'slug']);
    $opLogBefore = batchedColumnMap($db, 'op_log_entries', $user->id, ['value', 'gdk_epoch', 'signature']);
    $bystanderBefore = batchedColumnMap($db, 'transactions', $other->id, array_merge($snapshotColumns, ['source_ref']));

    $snapshot = new PreMigrationSnapshot(batchedCopyingEncryptor(), app(Clock::class));
    $path = $snapshot->takeSnapshot((int) $user->id, $connection, str_repeat("\x2a", 32));

    // Stand in for a half-finished encrypt pass: every snapshotted column
    // overwritten, every op-log row stamped with an epoch, plus a row that did
    // not exist when the snapshot was taken.
    $connection->table('transactions')->where('user_id', $user->id)->update([
        'note' => 'CIPHERTEXT', 'description' => 'CIPHERTEXT', 'counterparty_name' => 'CIPHERTEXT',
        'counterparty_iban' => 'CIPHERTEXT', 'raw_payload' => 'CIPHERTEXT',
    ]);
    $connection->table('counterparties')->where('user_id', $user->id)->update([
        'display_name' => 'CIPHERTEXT', 'merchant_name' => 'CIPHERTEXT', 'iban' => 'CIPHERTEXT',
    ]);
    $connection->table('notifications')->where('user_id', $user->id)->update([
        'title' => 'CIPHERTEXT', 'body' => 'CIPHERTEXT', 'params' => 'CIPHERTEXT', 'trigger_type' => 'CIPHERTEXT',
    ]);
    $connection->table('op_log_entries')->where('user_id', $user->id)->update([
        'value' => 'CIPHERTEXT', 'gdk_epoch' => 7,
    ]);
    $connection->table('transactions')->insert(batchedTransactionRow($user, $account, $importRun, 98_001));
    $latecomerId = (int) $connection->table('transactions')->where('user_id', $user->id)->max('id');

    $statements = [];
    $connection->listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $snapshot->restoreFromSnapshot($path, str_repeat("\x2a", 32), $connection);

    $restoreWrites = count(array_filter(
        $statements,
        static fn (string $sql): bool => str_starts_with($sql, 'update "transactions" set'),
    ));
    expect($restoreWrites)->toBeLessThan(20)->toBeGreaterThan(0);
    expect(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'case "id" when')))->not->toBeEmpty();

    $after = batchedColumnMap($db, 'transactions', $user->id, array_merge($snapshotColumns, ['source_ref', 'status', 'amount_minor']));
    foreach ($before as $id => $values) {
        expect($after[$id])->toBe($values);
    }
    expect($after[$latecomerId]['description'])->toBe('Albert Heijn 1234 pinbetaling 98001');

    expect(batchedColumnMap($db, 'counterparties', $user->id, ['display_name', 'merchant_name', 'iban', 'slug']))->toBe($counterpartiesBefore);
    expect(batchedColumnMap($db, 'notifications', $user->id, ['title', 'body', 'params', 'trigger_type', 'state']))->toBe($notificationsBefore);
    expect(batchedColumnMap($db, 'transactions', $other->id, array_merge($snapshotColumns, ['source_ref'])))->toBe($bystanderBefore);

    $opLogAfter = batchedColumnMap($db, 'op_log_entries', $user->id, ['value', 'gdk_epoch', 'signature']);
    foreach ($opLogBefore as $id => $values) {
        if ($values['value'] === null) {
            continue;
        }
        expect($opLogAfter[$id])->toBe($values);
    }

    $bystanderNotification = $connection->table('notifications')->where('user_id', $other->id)->first();
    expect($bystanderNotification->title)->toBe('bystander title');

    @unlink($path);
});

// The restore stages a decrypted copy of every sensitive column the migration
// is about to rewrite. The encryptor's file-path API renames its own staging
// file into place at the process umask default, so the narrowing has to happen
// here, before the first plaintext byte is read back out.
it('narrows the restored snapshot plaintext to 0600 before a byte of it is read', function (): void {
    $user = batchedUser('restore-perms');
    [$account, $importRun] = batchedLedgerScaffold($user, 'restore-perms');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $connection = $db->connection();
    $connection->table('transactions')->insert(batchedTransactionRow($user, $account, $importRun, 97_001));

    $snapshot = new PreMigrationSnapshot(batchedCopyingEncryptor(), app(Clock::class));
    $path = $snapshot->takeSnapshot((int) $user->id, $connection, str_repeat("\x2a", 32));

    // The staged file exists only for the length of the restore, so its mode is
    // read from inside the replay it is feeding rather than afterwards.
    $stagedMode = null;
    $stagingGlob = dirname($path).DIRECTORY_SEPARATOR.'beatrax_premig_restore_*.tmp';
    $connection->listen(function () use (&$stagedMode, $stagingGlob): void {
        foreach (glob($stagingGlob) ?: [] as $staged) {
            $stagedMode ??= fileperms($staged) & 0777;
        }
    });

    $snapshot->restoreFromSnapshot($path, str_repeat("\x2a", 32), $connection);

    expect($stagedMode)->toBe(0600, 'a decrypted snapshot readable by any local account is the leak the whole store exists to prevent');
});
