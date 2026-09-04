<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Merge\OpLogEntryApplier;
use Modules\Sync\Internal\Merge\RowOwnership;
use Modules\Sync\Internal\Merge\SearchDocumentRows;
use Modules\Sync\Internal\Merge\SplitCreateTail;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

// A peer's history arrives in transport frames and nothing keeps one row's
// create ops inside a single frame. The half that lands second names a row
// this device already holds and carries required columns it will not repeat,
// so judging it an incomplete create quarantines the only carrier of the
// columns the first half never had.

function splitTailUser(string $u): User
{
    return User::query()->create(['username' => $u, 'password' => bcrypt('fixture'), 'period_start_day' => 1, 'default_currency_view' => 'eur_only']);
}

function splitTailEntry(int $pk, string $field, mixed $value, int $userId): OpLogEntry
{
    return new OpLogEntry(
        userId: $userId, deviceId: 'split-device', table: 'transactions', pk: $pk,
        field: $field, opType: OpType::CreateRow, value: json_encode($value, JSON_THROW_ON_ERROR),
        hlcL: 1, hlcC: 0, signature: str_repeat('0', 128),
    );
}

it('keeps the tail of a create that was split across two batches', function (): void {
    $db = app(DatabaseManager::class);
    $userId = (int) splitTailUser('split-create')->id;

    test()->actingAs(User::query()->findOrFail($userId));
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));
    app(GdkKeyringService::class)->generateAndPersist($userId, $session);

    $accountId = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'Main', 'slug' => 'main-'.$userId, 'kind' => 'checking',
        'iban' => 'NL00SPLIT'.str_pad((string) $userId, 9, '0', STR_PAD_LEFT), 'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $runId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'demo', 'raw_file_path' => 'i.csv',
        'sha256' => str_repeat('a', 64), 'uploaded_at' => '2026-06-01 00:00:00',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $cpId = (int) $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'type' => 'government', 'slug' => 'belastingdienst',
        'display_name' => 'Belastingdienst', 'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    // Everything the registry marks required lands in the first batch, so the
    // row is admissible and inserts. The tail of the SAME create follows in the
    // next one, which is what a frame boundary does to a 34-column row.
    $head = [
        'account_id' => $accountId, 'type' => 'expense', 'posted_at' => '2026-06-10',
        'booked_at' => '2026-06-10 12:00:00', 'value_date' => '2026-06-10',
        'amount_minor' => -5511, 'currency' => 'EUR', 'settled_amount_minor' => -5511,
        'settled_currency' => 'EUR', 'counterparty_normalized' => 'belastingdienst',
        'normalization_version' => 3, 'source_format' => 'demo', 'import_run_id' => $runId,
        'source_row_index' => 1, 'fingerprint' => str_repeat('7', 64), 'fingerprint_version' => 3,
        'status' => 'cleared', 'created_at' => '2026-06-10 12:00:00',
    ];
    $tail = ['payment_type' => 'direct_debit', 'counterparty_id' => $cpId];

    $batchOne = [];
    foreach ($head as $f => $v) {
        $batchOne['transactions'][157][$f] = [splitTailEntry(157, $f, $v, $userId)];
    }

    // The tail carries created_at too: it is the same row, so the peer sends
    // the same birth time, and that is exactly what makes the collision check
    // answer "not a contradiction".
    $batchTwo = [];
    foreach ($tail + ['created_at' => '2026-06-10 12:00:00'] as $f => $v) {
        $batchTwo['transactions'][157][$f] = [splitTailEntry(157, $f, $v, $userId)];
    }

    $touched = new SearchDocumentRows($db);
    $applier = app(OpLogEntryApplier::class);
    $applier->applyCreates($batchOne, [], $userId, '2026-06-10 12:00:00', $touched);
    $applier->applyCreates($batchTwo, [], $userId, '2026-06-10 12:00:00', $touched);

    $row = $db->connection()->table('transactions')->where('id', 157)->first();

    expect($row)->not->toBeNull()
        ->and($row->payment_type)->toBe('direct_debit', 'the tail of the create must not be discarded')
        ->and((int) $row->counterparty_id)->toBe($cpId);
});

it('does not call the rest of a create a collision when it seeded the birth time itself', function (): void {
    $db = app(DatabaseManager::class);
    $userId = (int) splitTailUser('split-seeded-time')->id;

    test()->actingAs(User::query()->findOrFail($userId));
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));
    app(GdkKeyringService::class)->generateAndPersist($userId, $session);

    $accountId = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'Main', 'slug' => 'main-'.$userId, 'kind' => 'checking',
        'iban' => 'NL00SEED'.str_pad((string) $userId, 10, '0', STR_PAD_LEFT), 'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $runId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'demo', 'raw_file_path' => 'i.csv',
        'sha256' => str_repeat('b', 64), 'uploaded_at' => '2026-06-01 00:00:00',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    // The half that lands first carries no created_at, so the applier seeds one
    // from the op's HLC. That invented value is what made the REST of the same
    // create read as a different row wearing id 401 — quarantined as a primary
    // key collision, on a device that had never seen the id before.
    $head = [
        'account_id' => $accountId, 'type' => 'expense', 'posted_at' => '2026-06-10',
        'booked_at' => '2026-06-10 12:00:00', 'value_date' => '2026-06-10',
        'amount_minor' => -1999, 'currency' => 'EUR', 'settled_amount_minor' => -1999,
        'settled_currency' => 'EUR', 'counterparty_normalized' => 'seeded',
        'normalization_version' => 3, 'source_format' => 'demo', 'import_run_id' => $runId,
        'source_row_index' => 9, 'fingerprint' => str_repeat('5', 64), 'fingerprint_version' => 3,
        'status' => 'cleared',
    ];

    $batchOne = [];
    foreach ($head as $f => $v) {
        $batchOne['transactions'][401][$f] = [splitTailEntry(401, $f, $v, $userId)];
    }

    // The whole create comes back — a recoverable quarantine re-drive delivers
    // it complete — so this half DOES reach the insert, fails on the primary
    // key, and is judged against the row the first half wrote.
    $batchTwo = [];
    foreach ($head + ['created_at' => '2026-06-10 12:00:00', 'payment_type' => 'pin'] as $f => $v) {
        $batchTwo['transactions'][401][$f] = [splitTailEntry(401, $f, $v, $userId)];
    }

    $touched = new SearchDocumentRows($db);
    $applier = app(OpLogEntryApplier::class);
    $applier->applyCreates($batchOne, [], $userId, '2026-06-10 12:00:00', $touched);
    $applier->applyCreates($batchTwo, [], $userId, '2026-06-10 12:00:00', $touched);

    expect($db->connection()->table('op_log_quarantine')->where('reason', 'primary_key_collision')->count())
        ->toBe(0, 'one create split in two is not two devices minting one id')
        ->and($db->connection()->table('transactions')->where('id', 401)->value('payment_type'))
        ->toBe('pin')
        // And the invented birth time gives way to the one the peer actually
        // sent. Left alone it survives every later sync, and the lists that
        // order by created_at put the row in the wrong place forever.
        ->and($db->connection()->table('transactions')->where('id', 401)->value('created_at'))
        ->toBe('2026-06-10 12:00:00');
});

it('never moves a birth time that came off the wire', function (): void {
    $db = app(DatabaseManager::class);
    $userId = (int) splitTailUser('split-wire-time')->id;

    test()->actingAs(User::query()->findOrFail($userId));
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));
    app(GdkKeyringService::class)->generateAndPersist($userId, $session);

    $accountId = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'Main', 'slug' => 'main-'.$userId, 'kind' => 'checking',
        'iban' => 'NL00WIRE'.str_pad((string) $userId, 10, '0', STR_PAD_LEFT), 'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $runId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'demo', 'raw_file_path' => 'i.csv',
        'sha256' => str_repeat('c', 64), 'uploaded_at' => '2026-06-01 00:00:00',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    $head = [
        'account_id' => $accountId, 'type' => 'expense', 'posted_at' => '2026-06-10',
        'booked_at' => '2026-06-10 12:00:00', 'value_date' => '2026-06-10',
        'amount_minor' => -700, 'currency' => 'EUR', 'settled_amount_minor' => -700,
        'settled_currency' => 'EUR', 'counterparty_normalized' => 'wire',
        'normalization_version' => 3, 'source_format' => 'demo', 'import_run_id' => $runId,
        'source_row_index' => 4, 'fingerprint' => str_repeat('3', 64), 'fingerprint_version' => 3,
        'status' => 'cleared', 'created_at' => '2026-06-10 09:00:00',
    ];

    $first = [];
    foreach ($head as $f => $v) {
        $first['transactions'][402][$f] = [splitTailEntry(402, $f, $v, $userId)];
    }

    $second = [];
    foreach ($head + ['created_at' => '2026-06-10 23:59:59'] as $f => $v) {
        $second['transactions'][402][$f] = [splitTailEntry(402, $f, $v, $userId)];
    }

    $touched = new SearchDocumentRows($db);
    $applier = app(OpLogEntryApplier::class);
    $applier->applyCreates($first, [], $userId, '2026-06-10 12:00:00', $touched);
    $applier->applyCreates($second, [], $userId, '2026-06-10 12:00:00', $touched);

    // The stored time came off the wire, so it is the peer's answer and not
    // this device's guess. A second create carrying a different one is a real
    // disagreement for the collision check, never something to overwrite.
    expect($db->connection()->table('transactions')->where('id', 402)->value('created_at'))
        ->toBe('2026-06-10 09:00:00');
});

// The tail is written as one statement, so a single column whose foreign key
// target has not landed costs every column beside it. Nothing comes back for
// it: no quarantine row, no retry, and the row count still matches the peer's.
// The refusal is tolerated -- the row is applied and usable -- but a merge that
// drops a column has to leave a trace somewhere a reader can find it.
it('says which columns it lost when the tail of a create is refused', function (): void {
    $db = app(DatabaseManager::class);
    $userId = (int) splitTailUser('split-tail-refused')->id;

    $accountId = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'Main', 'slug' => 'main-'.$userId, 'kind' => 'checking',
        'iban' => 'NL00LOST'.str_pad((string) $userId, 10, '0', STR_PAD_LEFT), 'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $runId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'demo', 'raw_file_path' => 'i.csv',
        'sha256' => str_repeat('d', 64), 'uploaded_at' => '2026-06-01 00:00:00',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $db->connection()->table('transactions')->insert([
        'id' => 512, 'user_id' => $userId, 'account_id' => $accountId, 'type' => 'expense',
        'posted_at' => '2026-06-10', 'booked_at' => '2026-06-10 12:00:00', 'value_date' => '2026-06-10',
        'amount_minor' => -2500, 'currency' => 'EUR', 'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR', 'counterparty_normalized' => 'refused',
        'normalization_version' => 3, 'source_format' => 'demo', 'import_run_id' => $runId,
        'source_row_index' => 2, 'fingerprint' => str_repeat('9', 64), 'fingerprint_version' => 3,
        'status' => 'cleared', 'created_at' => '2026-06-10 12:00:00', 'updated_at' => '2026-06-10 12:00:00',
    ]);

    $said = [];
    $logger = new class($said) implements LoggerInterface
    {
        use LoggerTrait;

        /** @param array<int, array{0: mixed, 1: array<string, mixed>}> $said */
        public function __construct(private array &$said) {}

        /** @param array<string, mixed> $context */
        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->said[] = [$message, $context];
        }
    };

    $tail = new SplitCreateTail($db, new RowOwnership($db), $logger);
    $tail->fill('transactions', 512, ['category_id' => 987654, 'payment_type' => 'pin'], $userId);

    $row = $db->connection()->table('transactions')->where('id', 512)->first();

    expect($row?->payment_type)->toBe('unknown', 'the whole tail goes down with the one column that was refused')
        ->and($row?->category_id)->toBeNull()
        ->and($said)->toHaveCount(1, 'a tail nothing retries must not be dropped in silence')
        ->and($said[0][1]['columns'])->toBe(['category_id', 'payment_type'])
        ->and($said[0][1]['table'])->toBe('transactions')
        ->and($said[0][1])->not->toHaveKey('exception');
});
