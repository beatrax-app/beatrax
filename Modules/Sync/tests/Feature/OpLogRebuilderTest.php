<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogRebuilder;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// A rebuild has to land on exactly what incremental replay produced, for
// imported rows it preserves and op-log-created rows it deletes and recreates
// alike. Nothing instantiated the rebuilder before this file, and its own
// docblock cited a guard test that did not exist.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 10:00:00');
    $this->db = app(DatabaseManager::class);
    $this->db->connection()->statement('PRAGMA foreign_keys=ON');
    $this->signer = new DeviceKeySigner;
    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->pkHex = bin2hex(sodium_crypto_sign_publickey($keypair));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array{0: int, 1: int, 2: int}
 */
function rebuildSeedBase(DatabaseManager $db, string $suffix): array
{
    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'rebuild-u-'.$suffix,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN rebuild '.$suffix,
        'slug' => 'rebuild-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(substr(md5($suffix), 0, 8)),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rebuild-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'rebuild-run-'.$suffix),
        'uploaded_at' => '2026-06-15 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    return [$userId, $accountId, $runId];
}

function rebuildSeedImportedTxn(DatabaseManager $db, int $userId, int $accountId, int $runId, string $type = 'expense', int $amountMinor = -1234, int $rowIndex = 1): int
{
    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'rebuild-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-15',
        'booked_at' => '2026-06-15 10:0'.($rowIndex % 10).':00',
        'value_date' => '2026-06-15',
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'imported merchant '.$rowIndex,
        'counterparty_name' => 'IMPORTED MERCHANT',
        'normalization_version' => 3,
        'description' => 'imported row',
        'type' => $type,
        'source_format' => 'asn-csv',
        'source_row_index' => $rowIndex,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);
}

function rebuildSignedEntry(
    DeviceKeySigner $signer,
    string $sk,
    int $userId,
    string $table,
    int|string $pk,
    string $field,
    ?string $value,
    OpType $opType,
    int $hlcL,
    int $hlcC = 0,
    string $deviceId = 'device-rebuild',
): OpLogEntry {
    $stub = new OpLogEntry(
        table: $table, pk: $pk, field: $field, value: $value,
        hlcL: $hlcL, hlcC: $hlcC, deviceId: $deviceId,
        opType: $opType, signature: '', userId: $userId,
    );
    $sig = $signer->sign($stub->signingPayload(), $sk);

    return new OpLogEntry(
        table: $table, pk: $pk, field: $field, value: $value,
        hlcL: $hlcL, hlcC: $hlcC, deviceId: $deviceId,
        opType: $opType, signature: $sig, userId: $userId,
    );
}

it('rebuild() preserves an imported row and reproduces its SET edits — rebuilt == incremental (CR-04)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->db;
    [$userId, $accountId, $runId] = rebuildSeedBase($db, 'imp');

    $txnId = rebuildSeedImportedTxn($db, $userId, $accountId, $runId);

    $catId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId, 'name' => 'RebuildCat', 'slug' => 'rebuild-cat',
        'kind' => 'expense', 'created_at' => '2026-06-15 00:00:00', 'updated_at' => '2026-06-15 00:00:00',
    ]);

    $deviceKeys = ['device-rebuild' => $this->pkHex];
    $replayer = new OpLogReplayer($db, $deviceKeys);
    $replayer->replay([
        rebuildSignedEntry($this->signer, $this->sk, $userId, 'transactions', $txnId, 'category_id', (string) $catId, OpType::Set, 1000),
        rebuildSignedEntry($this->signer, $this->sk, $userId, 'transactions', $txnId, 'note', json_encode('hello', JSON_THROW_ON_ERROR), OpType::Set, 1001),
    ], $userId);

    $incremental = $db->connection()->table('transactions')->where('id', $txnId)->first();
    expect((int) $incremental->category_id)->toBe($catId);
    expect($incremental->note)->toBe('hello');

    $rebuilder = new OpLogRebuilder($db, $replayer, new MergeRulesRegistry, ['transactions']);
    $rebuilder->rebuild($userId);

    // The imported row is preserved rather than wiped, and keeps its edits.
    $rebuilt = $db->connection()->table('transactions')->where('id', $txnId)->first();
    expect($rebuilt)->not->toBeNull();
    expect((int) $rebuilt->category_id)->toBe($catId);
    expect($rebuilt->note)->toBe('hello');
    expect($rebuilt->type)->toBe($incremental->type); // unchanged base column preserved
});

it('rebuild() recreates an op-log-CREATED row from CreateRow ops (CR-04)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->db;
    [$userId] = rebuildSeedBase($db, 'cr');

    // Idempotency comes from the primary key now: the natural-key UNIQUE that
    // used to make a re-applied create harmless is gone, so the pk travels as
    // an explicit id field and the rebuild deletes by that id before recreating.
    $pk = 8801;
    $deviceKeys = ['device-rebuild' => $this->pkHex];
    $replayer = new OpLogReplayer($db, $deviceKeys);

    $creates = [
        rebuildSignedEntry($this->signer, $this->sk, $userId, 'categorization_rules', $pk, 'id', (string) $pk, OpType::CreateRow, 1000),
        rebuildSignedEntry($this->signer, $this->sk, $userId, 'categorization_rules', $pk, 'priority', '777', OpType::CreateRow, 1000),
        rebuildSignedEntry($this->signer, $this->sk, $userId, 'categorization_rules', $pk, 'combinator', json_encode('all', JSON_THROW_ON_ERROR), OpType::CreateRow, 1000),
        rebuildSignedEntry($this->signer, $this->sk, $userId, 'categorization_rules', $pk, 'hits_count', '0', OpType::CreateRow, 1000),
        rebuildSignedEntry($this->signer, $this->sk, $userId, 'categorization_rules', $pk, 'active', 'true', OpType::CreateRow, 1000),
    ];
    $replayer->replay($creates, $userId);

    $beforeCount = $db->connection()->table('categorization_rules')->where('user_id', $userId)->where('id', $pk)->count();
    expect($beforeCount)->toBe(1);

    $rebuilder = new OpLogRebuilder($db, $replayer, new MergeRulesRegistry, ['categorization_rules']);
    $rebuilder->rebuild($userId);

    $afterCount = $db->connection()->table('categorization_rules')->where('user_id', $userId)->where('id', $pk)->count();
    expect($afterCount)->toBe(1);
});

it('rebuild() restores triggers after the maintenance window (CR-04)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->db;
    [$userId, $accountId, $runId] = rebuildSeedBase($db, 'trig');
    rebuildSeedImportedTxn($db, $userId, $accountId, $runId);

    $replayer = new OpLogReplayer($db, ['device-rebuild' => $this->pkHex]);
    $rebuilder = new OpLogRebuilder($db, $replayer, new MergeRulesRegistry, ['transactions']);
    $rebuilder->rebuild($userId);

    $triggers = $db->connection()->select(
        "SELECT name FROM sqlite_master WHERE type='trigger' AND name LIKE 'transactions_%_check_%'"
    );
    $names = array_column($triggers, 'name');
    expect($names)->toContain('transactions_type_check_insert');
    expect($names)->toContain('transactions_type_check_update');
    expect($names)->toContain('transactions_payment_type_check_insert');
    expect($names)->toContain('transactions_payment_type_check_update');
});

it('pair-link cascade reclassification survives a full rebuild (CR-03)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->db;
    [$userId, $accountId, $runId] = rebuildSeedBase($db, 'cascade');

    $txnA = rebuildSeedImportedTxn($db, $userId, $accountId, $runId, 'transfer_out', -5000, 1);
    $txnB = rebuildSeedImportedTxn($db, $userId, $accountId, $runId, 'transfer_in', 5000, 2);
    $db->connection()->table('transactions')->where('id', $txnA)->update(['pair_transaction_id' => $txnB]);
    $db->connection()->table('transactions')->where('id', $txnB)->update(['pair_transaction_id' => $txnA]);

    // Tombstoning one leg reclassifies the survivor: it is no longer a transfer.
    $replayer = new OpLogReplayer($db, ['device-rebuild' => $this->pkHex]);
    $tomb = rebuildSignedEntry($this->signer, $this->sk, $userId, 'transactions', $txnA, '__tombstone__', null, OpType::DeleteTombstone, 2000);
    $replayer->replay([$tomb], $userId);

    expect($db->connection()->table('transactions')->where('id', $txnB)->value('type'))->toBe('income');

    // The compensating op carries a real HLC that sorts after the tombstone.
    $comp = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('device_id', OpLogReplayer::SYSTEM_CASCADE_DEVICE_ID)
        ->where('pk', (string) $txnB)
        ->where('field', 'type')
        ->first();
    expect($comp)->not->toBeNull();
    expect((int) $comp->hlc_l)->toBe(2000);
    expect((int) $comp->hlc_c)->toBe(1); // tombstone counter + 1

    // The rebuild must keep the reclassification: a compensating op at HLC
    // [0,0] sorted first and the survivor reverted on every rebuild.
    $rebuilder = new OpLogRebuilder($db, $replayer, new MergeRulesRegistry, ['transactions']);
    $rebuilder->rebuild($userId);

    expect($db->connection()->table('transactions')->where('id', $txnB)->value('type'))->toBe('income');

    $badQuarantine = $db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('device_id', OpLogReplayer::SYSTEM_CASCADE_DEVICE_ID)
        ->whereIn('reason', ['missing_device_key', 'forged_signature'])
        ->count();
    expect($badQuarantine)->toBe(0);
});

it('confines DROP TRIGGER to the rebuilder and trigger-owning migrations (IN-01 grep-guard)', function (): void {
    $root = dirname(__DIR__, 4); // .../<repo root>
    $syncSrc = $root.'/Modules/Sync/Internal';

    $offenders = [];
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($syncSrc, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($rii as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        // The rebuilder is the only Sync production file permitted to DROP TRIGGER.
        if (str_ends_with($path, 'OpLog/OpLogRebuilder.php')) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        if (stripos($contents, 'DROP TRIGGER') !== false) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});

it('re-derives the full-text index after a rebuild instead of leaving it behind', function (): void {
    // The rebuild suppresses search writes inside its transaction, and nothing
    // put them back afterwards: every rebuilt row lost its search doc. On a
    // real phone 18 of 141 transactions simply stopped being findable, with no
    // symptom but fewer results than the screen showed.
    $searchWriter = new class implements SearchIndexWriterContract
    {
        /** @var list<int> */
        public array $indexed = [];

        public function upsertForTransaction(int $transactionId, int $actorUserId): void
        {
            $this->indexed[] = $transactionId;
        }

        public function deleteForTransaction(int $transactionId, int $actorUserId): void {}
    };

    $db = $this->db;
    [$userId, $accountId, $runId] = rebuildSeedBase($db, 'fts');
    $txnId = rebuildSeedImportedTxn($db, $userId, $accountId, $runId);

    $replayer = new OpLogReplayer($db, ['device-rebuild' => $this->pkHex]);
    $replayer->replay([
        rebuildSignedEntry($this->signer, $this->sk, $userId, 'transactions', $txnId, 'note', json_encode('findable', JSON_THROW_ON_ERROR), OpType::Set, 1000),
    ], $userId);

    $rebuilder = new OpLogRebuilder($db, $replayer, new MergeRulesRegistry, ['transactions'], null, $searchWriter);
    $rebuilder->rebuild($userId);

    expect(in_array($txnId, $searchWriter->indexed, true))
        ->toBeTrue('the rebuilt transaction was never re-indexed');
});
