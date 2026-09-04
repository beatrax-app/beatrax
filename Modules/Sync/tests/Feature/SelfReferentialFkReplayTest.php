<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Merge\OpLogEntryApplier;
use Modules\Sync\Internal\Merge\SearchDocumentRows;
use Modules\Sync\Internal\Merge\SelfReferenceDeferral;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

// transactions.pair_transaction_id is a foreign key onto transactions itself and
// a transfer pair points both ways, so no insert order satisfies it: whichever
// row lands first names a partner that does not exist yet. On a phone pulling a
// desktop's history that threw FOREIGN KEY constraint failed and rolled the replay back.

function selfRefUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function selfRefAccount(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Main',
        'slug' => 'main-'.$userId,
        'kind' => 'checking',
        'iban' => 'NL00SELF'.str_pad((string) $userId, 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function selfRefUnlock(int $userId): void
{
    test()->actingAs(User::query()->findOrFail($userId));

    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));
    app(GdkKeyringService::class)->generateAndPersist($userId, $session);
}

function selfRefEntry(int $pk, string $field, string $value, int $userId, int $hlc): OpLogEntry
{
    return new OpLogEntry(
        userId: $userId,
        deviceId: 'self-ref-device',
        table: 'transactions',
        pk: $pk,
        field: $field,
        opType: OpType::CreateRow,
        value: $value,
        hlcL: $hlc,
        hlcC: 0,
        signature: str_repeat('0', 128),
    );
}

/**
 * @return array<string, array<int|string, array<string, list<OpLogEntry>>>>
 */
function selfRefPairCreates(int $userId, int $accountId, int $importRunId): array
{
    $creates = [];

    // 251 names 295 as its partner and 295 names 251 — the real shape from
    // the failing log, carrying every column the registry marks required.
    foreach ([[251, 295, 'transfer_out', -10000], [295, 251, 'transfer_in', 10000]] as [$pk, $partner, $type, $amount]) {
        foreach ([
            'account_id' => $accountId,
            'type' => $type,
            'posted_at' => '2026-06-10',
            'booked_at' => '2026-06-10 12:00:00',
            'value_date' => '2026-06-10',
            'amount_minor' => $amount,
            'currency' => 'EUR',
            'settled_amount_minor' => $amount,
            'settled_currency' => 'EUR',
            'counterparty_normalized' => 'partner',
            'normalization_version' => 3,
            'source_format' => 'demo',
            'import_run_id' => $importRunId,
            'source_row_index' => $pk,
            'fingerprint' => str_repeat((string) ($pk % 10), 64),
            'fingerprint_version' => 3,
            'status' => 'cleared',
            'pair_transaction_id' => $partner,
        ] as $field => $value) {
            // op-log values are JSON scalars on the wire, not raw strings.
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
            $creates['transactions'][$pk][$field] = [selfRefEntry($pk, $field, $encoded, $userId, 1)];
        }
    }

    return $creates;
}

function selfRefImportRun(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'demo',
        'raw_file_path' => 'imports/self-ref.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => '2026-06-01 00:00:00',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

it('applies a mutually-referencing transfer pair without a foreign-key failure', function (): void {
    $db = app(DatabaseManager::class);

    $userId = (int) selfRefUser('self-ref-replay')->id;

    selfRefUnlock($userId);
    $accountId = selfRefAccount($db, $userId);

    $touched = new SearchDocumentRows($db);

    /** @var OpLogEntryApplier $applier */
    $applier = app(OpLogEntryApplier::class);

    $applier->applyCreates(
        selfRefPairCreates($userId, $accountId, selfRefImportRun($db, $userId)),
        [],
        $userId,
        '2026-06-10 12:00:00',
        $touched,
    );

    $rows = $db->connection()->table('transactions')
        ->whereIn('id', [251, 295])
        ->orderBy('id')
        ->get(['id', 'pair_transaction_id']);

    expect($rows)->toHaveCount(2, 'both halves of the pair must exist');

    // The deferred pass runs once both rows are present, so each side ends up
    // pointing at the other rather than being left null.
    expect((int) $rows[0]->pair_transaction_id)->toBe(295)
        ->and((int) $rows[1]->pair_transaction_id)->toBe(251);
});

it('leaves a self-reference null when its target never arrives', function (): void {
    $db = app(DatabaseManager::class);

    $userId = (int) selfRefUser('dangling-ref-replay')->id;

    selfRefUnlock($userId);
    $accountId = selfRefAccount($db, $userId);

    $creates = selfRefPairCreates($userId, $accountId, selfRefImportRun($db, $userId));
    // Drop the partner: 251 now points at a row that is not in this batch and
    // never will be, which must cost 251 its link and nothing more.
    unset($creates['transactions'][295]);

    $touched = new SearchDocumentRows($db);

    /** @var OpLogEntryApplier $applier */
    $applier = app(OpLogEntryApplier::class);

    $applier->applyCreates($creates, [], $userId, '2026-06-10 12:00:00', $touched);

    $row = $db->connection()->table('transactions')->where('id', 251)->first(['pair_transaction_id']);

    expect($row)->not->toBeNull('the row itself must still be applied')
        ->and($row->pair_transaction_id)->toBeNull();
});

it('resolves a self-reference whose target lands in a later batch', function (): void {
    $db = app(DatabaseManager::class);

    $userId = (int) selfRefUser('later-batch-replay')->id;

    selfRefUnlock($userId);
    $accountId = selfRefAccount($db, $userId);

    $creates = selfRefPairCreates($userId, $accountId, selfRefImportRun($db, $userId));

    // A backfill is replayed in batches, and nothing makes a transfer pair land
    // in one of them: on a phone's first sync the partner was 1,300 ops further
    // down the log, in a batch of its own.
    $first = ['transactions' => [251 => $creates['transactions'][251]]];
    $second = ['transactions' => [295 => $creates['transactions'][295]]];

    $touched = new SearchDocumentRows($db);

    /** @var OpLogEntryApplier $applier */
    $applier = app(OpLogEntryApplier::class);

    $applier->applyCreates($first, [], $userId, '2026-06-10 12:00:00', $touched);
    $applier->applyCreates($second, [], $userId, '2026-06-10 12:00:00', $touched);

    $rows = $db->connection()->table('transactions')
        ->whereIn('id', [251, 295])
        ->orderBy('id')
        ->get(['id', 'pair_transaction_id']);

    expect($rows)->toHaveCount(2, 'both halves of the pair must exist');

    // 251 named 295 while 295 did not exist yet. The link is not optional
    // decoration — an unpaired transfer_out is counted as money leaving the
    // household, so losing it moves what the reader is shown.
    expect((int) $rows[0]->pair_transaction_id)->toBe(295, '251 must find the partner that arrived after it')
        ->and((int) $rows[1]->pair_transaction_id)->toBe(251);
});

it('defers every self-referential foreign key the schema declares', function (): void {
    $db = app(DatabaseManager::class);

    $declared = (new ReflectionClass(SelfReferenceDeferral::class))
        ->getReflectionConstant('SELF_REFERENCES')?->getValue();

    expect($declared)->toBeArray();

    $inSchema = [];

    foreach ($db->connection()->select("select name from sqlite_master where type='table' and name not like 'sqlite_%'") as $table) {
        $name = (string) $table->name;

        foreach ($db->connection()->select('pragma foreign_key_list('.$db->connection()->getPdo()->quote($name).')') as $fk) {
            if ((string) $fk->table === $name) {
                $inSchema[$name][] = (string) $fk->from;
            }
        }
    }

    foreach ($inSchema as $table => $columns) {
        sort($columns);
        $covered = $declared[$table] ?? [];
        sort($covered);

        // A self-referential FK nobody defers fails the insert outright and
        // rolls the whole replay back, which is how this class came to exist.
        expect($covered)->toBe($columns, "{$table} declares a self-referential foreign key the deferral does not cover");
    }
});

it('resolves a self-reference from the log when the partner landed in a later session', function (): void {
    $db = app(DatabaseManager::class);

    $userId = (int) selfRefUser('later-session-replay')->id;

    selfRefUnlock($userId);
    $accountId = selfRefAccount($db, $userId);

    $creates = selfRefPairCreates($userId, $accountId, selfRefImportRun($db, $userId));

    // An import that spans several sync sessions gets a FRESH applier for each
    // one, so the in-memory carry cannot see a partner that lands after the
    // session holding it ended. Two appliers is what that looks like.
    $touched = new SearchDocumentRows($db);
    app()->make(OpLogEntryApplier::class)->applyCreates(
        ['transactions' => [251 => $creates['transactions'][251]]], [], $userId, '2026-06-10 12:00:00', $touched,
    );
    app()->forgetInstance(OpLogEntryApplier::class);
    app()->make(OpLogEntryApplier::class)->applyCreates(
        ['transactions' => [295 => $creates['transactions'][295]]], [], $userId, '2026-06-10 12:00:00', $touched,
    );

    expect($db->connection()->table('transactions')->where('id', 251)->value('pair_transaction_id'))
        ->toBeNull('a fresh applier cannot carry what the previous one held');

    foreach ([[251, 295], [295, 251]] as [$pk, $partner]) {
        $db->connection()->table('op_log_entries')->insert([
            'user_id' => $userId,
            'device_id' => 'self-ref-device',
            'table_name' => 'transactions',
            'pk' => (string) $pk,
            'field' => 'pair_transaction_id',
            'op_type' => 'create_row',
            'value' => json_encode($partner, JSON_THROW_ON_ERROR),
            'hlc_l' => 1,
            'hlc_c' => 0,
            'signature' => str_repeat('0', 128),
            'recorded_at' => '2026-06-10 12:00:00',
        ]);
    }

    // One, not two: 295 landed second and found 251 already here, so its own
    // batch resolved it. Only the leg that named a partner still to come is
    // left for the sweep.
    expect(app(SelfReferenceDeferral::class)->resolveFromHistory($userId))->toBe(1);

    $rows = $db->connection()->table('transactions')->whereIn('id', [251, 295])->orderBy('id')->get(['id', 'pair_transaction_id']);

    expect((int) $rows[0]->pair_transaction_id)->toBe(295)
        ->and((int) $rows[1]->pair_transaction_id)->toBe(251);
});

it('leaves a link alone when the sweep finds the column already filled', function (): void {
    $db = app(DatabaseManager::class);

    $userId = (int) selfRefUser('sweep-no-overwrite')->id;

    selfRefUnlock($userId);
    $accountId = selfRefAccount($db, $userId);

    $touched = new SearchDocumentRows($db);
    app(OpLogEntryApplier::class)->applyCreates(
        selfRefPairCreates($userId, $accountId, selfRefImportRun($db, $userId)), [], $userId, '2026-06-10 12:00:00', $touched,
    );

    // The log says 295, the row says otherwise. A sweep that reaches for a
    // column somebody already filled is a create talking over an edit.
    $db->connection()->table('transactions')->where('id', 251)->update(['pair_transaction_id' => null]);
    $db->connection()->table('transactions')->where('id', 295)->update(['pair_transaction_id' => 251]);

    $db->connection()->table('op_log_entries')->insert([
        'user_id' => $userId,
        'device_id' => 'self-ref-device',
        'table_name' => 'transactions',
        'pk' => '295',
        'field' => 'pair_transaction_id',
        'op_type' => 'create_row',
        'value' => json_encode(251, JSON_THROW_ON_ERROR),
        'hlc_l' => 1,
        'hlc_c' => 0,
        'signature' => str_repeat('0', 128),
        'recorded_at' => '2026-06-10 12:00:00',
    ]);

    expect(app(SelfReferenceDeferral::class)->resolveFromHistory($userId))->toBe(0);
});

it('says so when the log it repairs from cannot be read', function (): void {
    $db = app(DatabaseManager::class);

    $userId = (int) selfRefUser('unreadable-log')->id;

    selfRefUnlock($userId);

    $logger = new class extends AbstractLogger
    {
        /** @var list<array{0: mixed, 1: string, 2: array<string, mixed>}> */
        public array $records = [];

        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = [$level, (string) $message, $context];
        }
    };

    app()->instance(LoggerInterface::class, $logger);

    // The sweep reads the log to fill a link its own batch could not. Taking
    // the table away is the one failure that reproduces without a corrupt file:
    // renamed rather than dropped, and put back before the assertions, so the
    // worker this test shares is handed the schema it was given.
    $db->connection()->statement('ALTER TABLE op_log_entries RENAME TO op_log_entries_hidden');

    try {
        $repaired = app(SelfReferenceDeferral::class)->resolveFromHistory($userId);
    } finally {
        $db->connection()->statement('ALTER TABLE op_log_entries_hidden RENAME TO op_log_entries');
    }

    expect($repaired)->toBe(0, 'nothing can be repaired from a log that cannot be read')
        ->and($logger->records)->not->toBeEmpty('a sweep that failed must not answer like a clean one');

    expect($logger->records[0][0])->toBe('error')
        ->and($logger->records[0][1])->toContain('SelfReferenceDeferral')
        ->and($logger->records[0][2])->toHaveKeys(['table', 'column', 'exception']);
});
