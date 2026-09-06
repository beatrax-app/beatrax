<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

/**
 * @link ../../../../.docs/features/sync/oplog-replay-under-live-triggers.md
 */
function trigUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function trigTxn(DatabaseManager $db, int $userId, string $suffix, int $categoryId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN trigger probe',
        'slug' => 'trig-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/trig-probe-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'trig-probe-run-'.$suffix),
        'uploaded_at' => '2026-06-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'trig-probe-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-01',
        'booked_at' => '2026-06-01 10:00:00',
        'value_date' => '2026-06-01',
        'amount_minor' => -4999,
        'currency' => 'EUR',
        'settled_amount_minor' => -4999,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'albert heijn',
        'counterparty_name' => 'ALBERT HEIJN',
        'normalization_version' => 3,
        'description' => 'trigger probe fixture',
        'type' => 'expense',  // REQUIRED — transactions_type_check_insert trigger
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'category_id' => $categoryId,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');
    $this->user = trigUser('trig-probe-u');
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) $this->user->id;

    $this->catA = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'TrigCatA',
        'slug' => 'trig-cat-a',
        'kind' => 'expense',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $this->catB = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'TrigCatB',
        'slug' => 'trig-cat-b',
        'kind' => 'expense',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->pkHex = bin2hex(sodium_crypto_sign_publickey($keypair));
    $this->signer = new DeviceKeySigner;
    $this->deviceKeys = ['probe-device' => $this->pkHex];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it(
    'PROBE A: field-by-field UPDATE replay on existing transactions row composes with live triggers — no DROP/CREATE needed for UPDATE path',
    function (): void {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        $userId = (int) $this->user->id;

        $txnId = trigTxn($db, $userId, 'a', $this->catA);

        $triggers = $db->connection()->select(
            "SELECT name FROM sqlite_master WHERE type='trigger' AND name LIKE 'transactions_type_check%'"
        );
        $triggerNames = array_column($triggers, 'name');
        expect($triggerNames)->toContain('transactions_type_check_insert')
            ->and($triggerNames)->toContain('transactions_type_check_update');

        // The update trigger is BEFORE UPDATE OF type, so a category_id write misses it.
        $stub = new OpLogEntry(
            table: 'transactions',
            pk: $txnId,
            field: 'category_id',
            value: (string) $this->catB,
            hlcL: 1000,
            hlcC: 0,
            deviceId: 'probe-device',
            opType: OpType::Set,
            signature: '',
            userId: $userId,
        );
        $sig = $this->signer->sign($stub->signingPayload(), $this->sk);
        $entry = new OpLogEntry(
            table: 'transactions',
            pk: $txnId,
            field: 'category_id',
            value: (string) $this->catB,
            hlcL: 1000,
            hlcC: 0,
            deviceId: 'probe-device',
            opType: OpType::Set,
            signature: $sig,
            userId: $userId,
        );

        $replayer = new OpLogReplayer($db, $this->deviceKeys);

        $replayer->replay([$entry], $userId);

        $catId = $db->connection()
            ->table('transactions')
            ->where('id', $txnId)
            ->value('category_id');
        expect((int) $catId)->toBe($this->catB);

        // DROP/CREATE bracketing is the only route for an INSERT whose `type` is invalid.
        $insertTriggerDdl = $db->connection()->selectOne(
            "SELECT sql FROM sqlite_master WHERE type='trigger' AND name='transactions_type_check_insert'"
        );
        expect($insertTriggerDdl)->not->toBeNull();
        $insertTriggerSql = (string) $insertTriggerDdl->sql;

        $db->connection()->statement('DROP TRIGGER IF EXISTS transactions_type_check_insert');

        $accountId = $db->connection()->table('accounts')->insertGetId([
            'user_id' => $userId,
            'name' => 'Probe A rebuild acct',
            'slug' => 'trig-rebuild-acct',
            'kind' => 'bank',
            'iban' => 'NL00ASNBZZZZ',
            'default_currency' => 'EUR',
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-01 00:00:00',
        ]);
        $runId = $db->connection()->table('import_runs')->insertGetId([
            'user_id' => $userId,
            'source_format' => 'asn-csv',
            'raw_file_path' => '/tmp/rebuild-probe.csv',
            'sha256' => hash('sha256', 'rebuild-probe'),
            'uploaded_at' => '2026-06-01 00:00:00',
            'status' => 'previewed',
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-01 00:00:00',
        ]);

        $rebuildId = $db->connection()->table('transactions')->insertGetId([
            'user_id' => $userId,
            'account_id' => $accountId,
            'import_run_id' => $runId,
            'fingerprint' => hash('sha256', 'trig-rebuild-probe-'.bin2hex(random_bytes(8))),
            'posted_at' => '2026-06-01',
            'booked_at' => '2026-06-01 10:00:00',
            'value_date' => '2026-06-01',
            'amount_minor' => -9999,
            'currency' => 'EUR',
            'settled_amount_minor' => -9999,
            'settled_currency' => 'EUR',
            'counterparty_normalized' => 'rebuild-probe',
            'counterparty_name' => 'REBUILD PROBE',
            'normalization_version' => 3,
            'description' => 'rebuild probe row',
            'type' => 'INVALID_TYPE_FOR_PROBE',  // would fail trigger; passes with DROP
            'source_format' => 'asn-csv',
            'source_row_index' => 99,
            'fingerprint_version' => 3,
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-01 00:00:00',
        ]);

        expect($db->connection()->table('transactions')->where('id', $rebuildId)->exists())->toBeTrue();

        $db->connection()->statement($insertTriggerSql);

        $trigBack = $db->connection()->selectOne(
            "SELECT name FROM sqlite_master WHERE type='trigger' AND name='transactions_type_check_insert'"
        );
        expect($trigBack)->not->toBeNull();

        $logCount = $db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->count();
        expect($logCount)->toBeGreaterThanOrEqual(1);
    }
);

it(
    'PROBE B: CREATE_ROW op assembled from field ops creates a categorization_rules row — requires complete field snapshot',
    function (): void {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        $userId = (int) $this->user->id;

        $signer = $this->signer;
        $sk = $this->sk;

        /**
         * @param  string  $field
         * @param  string  $value
         */
        // The pk carried as an explicit `id` field op is the whole idempotency story
        // since the 2026-07-06 redesign dropped UNIQUE (user_id, field, match, value).
        $probePk = 8802;
        $makeCreate = static function (string $field, string $value) use ($signer, $sk, $userId, $probePk): OpLogEntry {
            $stub = new OpLogEntry(
                table: 'categorization_rules',
                pk: $probePk,
                field: $field,
                value: $value,
                hlcL: 1000,
                hlcC: 0,
                deviceId: 'probe-device',
                opType: OpType::CreateRow,
                signature: '',
                userId: $userId,
            );
            $sig = $signer->sign($stub->signingPayload(), $sk);

            return new OpLogEntry(
                table: 'categorization_rules',
                pk: $probePk,
                field: $field,
                value: $value,
                hlcL: 1000,
                hlcC: 0,
                deviceId: 'probe-device',
                opType: OpType::CreateRow,
                signature: $sig,
                userId: $userId,
            );
        };

        // Every NOT NULL column must be here; user_id alone is injected from the scope.
        $entries = [
            $makeCreate('id', (string) $probePk),
            $makeCreate('priority', '55'),
            $makeCreate('combinator', '"all"'),   // JSON-encoded string, enum-guarded by trigger
            $makeCreate('hits_count', '0'),
            $makeCreate('active', 'true'),
        ];

        $replayer = new OpLogReplayer($db, $this->deviceKeys);
        $replayer->replay($entries, $userId);

        $rules = $db->connection()
            ->table('categorization_rules')
            ->where('user_id', $userId)
            ->where('id', $probePk)
            ->get();

        expect($rules)->toHaveCount(1);

        $rule = $rules->first();
        expect((int) $rule->priority)->toBe(55)
            ->and($rule->combinator)->toBe('all')
            ->and((int) $rule->hits_count)->toBe(0)
            ->and((bool) $rule->active)->toBeTrue();

        $replayer->replay($entries, $userId);
        $countAfter = $db->connection()
            ->table('categorization_rules')
            ->where('user_id', $userId)
            ->where('id', $probePk)
            ->count();
        expect($countAfter)->toBe(1);

        $logCount = $db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->count();
        expect($logCount)->toBeGreaterThanOrEqual(5); // 5 field ops per replay; the second replay may dedup
    }
);

it(
    'PROBE C: tombstoning one side of a pair sets partner.pair_transaction_id=NULL (ON DELETE SET NULL) — partner row survives',
    function (): void {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        $userId = (int) $this->user->id;

        $txnAId = trigTxn($db, $userId, 'pair-a', $this->catA);
        $txnBId = trigTxn($db, $userId, 'pair-b', $this->catA);

        $db->connection()->table('transactions')
            ->where('id', $txnAId)
            ->update(['type' => 'transfer_out', 'pair_transaction_id' => $txnBId]);
        $db->connection()->table('transactions')
            ->where('id', $txnBId)
            ->update(['type' => 'transfer_in', 'pair_transaction_id' => $txnAId]);

        expect(
            $db->connection()->table('transactions')->where('id', $txnAId)->value('pair_transaction_id')
        )->toBe($txnBId);
        expect(
            $db->connection()->table('transactions')->where('id', $txnBId)->value('pair_transaction_id')
        )->toBe($txnAId);

        $stub = new OpLogEntry(
            table: 'transactions',
            pk: $txnAId,
            field: '__tombstone__',
            value: null,
            hlcL: 2000,
            hlcC: 0,
            deviceId: 'probe-device',
            opType: OpType::DeleteTombstone,
            signature: '',
            userId: $userId,
        );
        $sig = $this->signer->sign($stub->signingPayload(), $this->sk);
        $tombEntry = new OpLogEntry(
            table: 'transactions',
            pk: $txnAId,
            field: '__tombstone__',
            value: null,
            hlcL: 2000,
            hlcC: 0,
            deviceId: 'probe-device',
            opType: OpType::DeleteTombstone,
            signature: $sig,
            userId: $userId,
        );

        $replayer = new OpLogReplayer($db, $this->deviceKeys);
        $replayer->replay([$tombEntry], $userId);

        $txnAExists = $db->connection()->table('transactions')->where('id', $txnAId)->exists();
        expect($txnAExists)->toBeFalse();

        // ON DELETE SET NULL orphans the partner rather than cascade-deleting it.
        $txnBExists = $db->connection()->table('transactions')->where('id', $txnBId)->exists();
        expect($txnBExists)->toBeTrue();

        $txnBPairId = $db->connection()->table('transactions')->where('id', $txnBId)->value('pair_transaction_id');
        expect($txnBPairId)->toBeNull();

        $logCount = $db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->count();
        expect($logCount)->toBeGreaterThanOrEqual(1);
    }
);

it(
    'forged-signature entry is skipped — the DB row is not mutated',
    function (): void {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        $userId = (int) $this->user->id;

        $txnId = trigTxn($db, $userId, 'forged', $this->catA);

        $originalCatId = $db->connection()->table('transactions')
            ->where('id', $txnId)
            ->value('category_id');
        expect((int) $originalCatId)->toBe($this->catA);

        $forgedKeypair = sodium_crypto_sign_keypair();
        $forgedSk = sodium_crypto_sign_secretkey($forgedKeypair);

        $stub = new OpLogEntry(
            table: 'transactions',
            pk: $txnId,
            field: 'category_id',
            value: (string) $this->catB,
            hlcL: 9999,
            hlcC: 0,
            deviceId: 'probe-device',
            opType: OpType::Set,
            signature: '',
            userId: $userId,
        );
        $forgedSig = $this->signer->sign($stub->signingPayload(), $forgedSk);

        $forgedEntry = new OpLogEntry(
            table: 'transactions',
            pk: $txnId,
            field: 'category_id',
            value: (string) $this->catB,
            hlcL: 9999,
            hlcC: 0,
            deviceId: 'probe-device',
            opType: OpType::Set,
            signature: $forgedSig,
            userId: $userId,
        );

        $replayer = new OpLogReplayer($db, $this->deviceKeys);
        $replayer->replay([$forgedEntry], $userId);

        $catIdAfter = $db->connection()->table('transactions')
            ->where('id', $txnId)
            ->value('category_id');
        expect((int) $catIdAfter)->toBe($this->catA);

        $quarantineCount = $db->connection()
            ->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->where('reason', 'forged_signature')
            ->count();
        expect($quarantineCount)->toBeGreaterThanOrEqual(1);
    }
);
