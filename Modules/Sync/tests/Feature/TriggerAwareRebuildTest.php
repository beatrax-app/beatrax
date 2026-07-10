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

/*
 * TriggerAwareRebuildTest — three probes for FINDINGS (Wave 3).
 *
 * These probes answer the three Open Questions from RESEARCH.md:
 *
 * PROBE A — FULL-REBUILD TRIGGER PROBE (Open Question 1)
 *   Does field-by-field replay of transactions.category_id through the
 *   live `transactions_type_check_update` trigger compose correctly?
 *   FINDING: UPDATE-only replay (not INSERT) does NOT fire the
 *   `transactions_type_check_insert` trigger — it fires only the
 *   `transactions_type_check_update` trigger which only guards the `type`
 *   column. Updating category_id via UPDATE does not trigger the type
 *   check at all. Field-by-field UPDATE replay composes correctly for
 *   existing rows; no DROP/CREATE bracketing is needed for UPDATE paths.
 *   However, a full-rebuild from scratch (DELETE all rows + INSERT from op-log)
 *   would fire the INSERT trigger and require valid `type` on every row,
 *   which means row-creation ops must include `type`. This probe demonstrates
 *   both: (a) UPDATE path composes without trigger interference; (b) the
 *   INSERT path requires DROP TRIGGER bracketing for type-check triggers
 *   when valid `type` is not present in the op-log field set.
 *
 * PROBE B — CREATE_ROW PROBE (Open Question 3)
 *   Can a `categorization_rules` row be created via op-log field ops
 *   without a full-row snapshot? Specifically: does the CREATE_ROW op
 *   assembled from individual field values produce a valid INSERT that
 *   satisfies NOT NULL constraints and enum-check triggers?
 *   FINDING: Yes — a CREATE_ROW op assembled from field ops works when
 *   ALL required columns (user_id, field, match, value, category_id,
 *   hits_count, active) are present. If any NOT NULL column is absent
 *   from the op-log field set, the INSERT fails. This means op-log row
 *   creation needs a "full-row snapshot" op (or the CREATE_ROW op must
 *   carry all required fields together), not just a subset of fields.
 *
 * PROBE C — PAIR-LINK CASCADE PROBE (Q4 from RESEARCH)
 *   When one side of a pair_transaction_id pair is tombstoned, does the
 *   partner's pair_transaction_id become NULL (ON DELETE SET NULL) without
 *   the partner row being deleted?
 *   FINDING: Yes — the ON DELETE SET NULL FK cascade on pair_transaction_id
 *   correctly orphans the partner (NULL) without deleting the partner row.
 *
 * All three probes assert their resolved path (so the test is GREEN).
 * The RAW FINDINGS are captured in the test names and assertion comments
 * for FINDINGS doc consumption.
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

/**
 * Seeds account → import_run → transaction with the given category_id.
 * Returns the transaction id.
 */
function trigTxn(DatabaseManager $db, int $userId, string $suffix, int $categoryId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN trigger probe',
        'slug' => 'trig-asn-'.$suffix,
        'kind' => 'asn',
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

    // Category for probe A and B.
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

/*
 * PROBE A: FULL-REBUILD TRIGGER PROBE
 *
 * FINDING: Field-by-field UPDATE replay on an existing transactions row
 * does NOT fire transactions_type_check_insert — only the UPDATE trigger
 * fires, and it only guards the `type` column. Updating `category_id`
 * via field-by-field UPDATE composes correctly with live triggers.
 *
 * The IMPORTANT boundary: this works because the row already exists.
 * For a full-rebuild-from-scratch (DELETE all + INSERT), the INSERT
 * trigger fires and requires valid `type`. The probe also demonstrates
 * that the DROP-TRIGGER / INSERT / CREATE-TRIGGER bracketing successfully
 * bypasses the type constraint for a bare INSERT without `type`.
 */
it(
    'PROBE A: field-by-field UPDATE replay on existing transactions row composes with live triggers — no DROP/CREATE needed for UPDATE path',
    function (): void {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        $userId = (int) $this->user->id;

        // Create a transaction row (valid, with type=expense).
        $txnId = trigTxn($db, $userId, 'a', $this->catA);

        // ASSERTION A1: Verify live triggers exist in sqlite_master.
        $triggers = $db->connection()->select(
            "SELECT name FROM sqlite_master WHERE type='trigger' AND name LIKE 'transactions_type_check%'"
        );
        $triggerNames = array_column($triggers, 'name');
        expect($triggerNames)->toContain('transactions_type_check_insert')
            ->and($triggerNames)->toContain('transactions_type_check_update');

        // REPLAY: SET category_id via op-log (field-by-field UPDATE).
        // This goes through the live transactions_type_check_update trigger.
        // The update trigger only fires BEFORE UPDATE OF type — not for category_id updates.
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

        // FINDING: This does NOT raise 'Invalid transactions.type value' because
        // updating category_id does not fire the type-check UPDATE trigger.
        // The UPDATE trigger is: "BEFORE UPDATE OF type" — only triggers when `type` column is set.
        $replayer->replay([$entry], $userId);

        // ASSERTION A2: category_id updated correctly (trigger did not abort).
        $catId = $db->connection()
            ->table('transactions')
            ->where('id', $txnId)
            ->value('category_id');
        expect((int) $catId)->toBe($this->catB);

        // PROBE: Now demonstrate the DROP-TRIGGER / INSERT / CREATE-TRIGGER bracketing.
        // This is the ONLY way to INSERT a row with an invalid `type` value.
        // A full-rebuild scenario from op-log requires valid type on every row,
        // OR uses DROP/CREATE bracketing to skip the enum-check trigger temporarily.
        // NOTE: Under RefreshDatabase this rolls back after the test — safe.

        // Save trigger DDL from sqlite_master.
        $insertTriggerDdl = $db->connection()->selectOne(
            "SELECT sql FROM sqlite_master WHERE type='trigger' AND name='transactions_type_check_insert'"
        );
        expect($insertTriggerDdl)->not->toBeNull();
        $insertTriggerSql = (string) $insertTriggerDdl->sql;

        // DROP the insert trigger.
        $db->connection()->statement('DROP TRIGGER IF EXISTS transactions_type_check_insert');

        // Prepare a rebuild row account + import_run.
        $accountId = $db->connection()->table('accounts')->insertGetId([
            'user_id' => $userId,
            'name' => 'Probe A rebuild acct',
            'slug' => 'trig-rebuild-acct',
            'kind' => 'asn',
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

        // INSERT with an invalid type value — would be ABORTED by the trigger.
        // With trigger DROPped, the INSERT succeeds (only NOT NULL + DB constraint
        // applies; type IS NOT NULL so we must provide a value, but it can be invalid).
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

        // ASSERTION A3: rebuild row inserted without trigger abort (trigger was DROPped).
        expect($db->connection()->table('transactions')->where('id', $rebuildId)->exists())->toBeTrue();

        // Restore the INSERT trigger (full DDL from sqlite_master).
        $db->connection()->statement($insertTriggerSql);

        // ASSERTION A4: trigger restored — verify it exists in sqlite_master again.
        $trigBack = $db->connection()->selectOne(
            "SELECT name FROM sqlite_master WHERE type='trigger' AND name='transactions_type_check_insert'"
        );
        expect($trigBack)->not->toBeNull();

        // Assert: production replayer persists the SET op to op_log_entries (SYNC-01).
        // RED: fails until Plan 11-02 wires op_log_entries writes in production replayer.
        $logCount = $db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->count();
        expect($logCount)->toBeGreaterThanOrEqual(1);
    }
);

/*
 * PROBE B: CREATE_ROW PROBE
 *
 * FINDING: A categorization_rules row CAN be created via CREATE_ROW op-log
 * entries when ALL required columns (user_id from scope + field, match, value,
 * category_id, hits_count, active) are provided as field ops. The replayer's
 * CREATE_ROW path assembles them into a single insertOrIgnore that satisfies
 * NOT NULL constraints and enum-check triggers.
 *
 * If any NOT NULL column is missing from the op-log field set, the INSERT
 * will fail with a constraint error. This means the CREATE_ROW op must carry
 * ALL required columns as a complete snapshot — partial field sets are
 * insufficient for row creation.
 */
it(
    'PROBE B: CREATE_ROW op assembled from field ops creates a categorization_rules row — requires complete field snapshot',
    function (): void {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        $userId = (int) $this->user->id;

        // We will create a categorization_rules row via CREATE_ROW ops.
        // All required non-null columns must be in the op-log field set.

        $signer = $this->signer;
        $sk = $this->sk;

        /**
         * @param  string  $field
         * @param  string  $value
         */
        // The pk IS the numeric row id (carried as an explicit `id` field op).
        // The 2026_07_06 redesign dropped the flat field/match/value/category_id
        // columns (moved to rule_conditions/rule_actions) and the old UNIQUE
        // (user_id, field, match, value); idempotency now comes from the PK.
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

        // Provide ALL required fields for the redesigned parent row: id (pk),
        // priority, combinator, active, hits_count. user_id is injected by the
        // replayer from the $userId scope parameter.
        $entries = [
            $makeCreate('id', (string) $probePk),
            $makeCreate('priority', '55'),
            $makeCreate('combinator', '"all"'),   // JSON-encoded string, enum-guarded by trigger
            $makeCreate('hits_count', '0'),
            $makeCreate('active', 'true'),
        ];

        $replayer = new OpLogReplayer($db, $this->deviceKeys);
        $replayer->replay($entries, $userId);

        // ASSERTION B1: exactly one new categorization_rules row created.
        $rules = $db->connection()
            ->table('categorization_rules')
            ->where('user_id', $userId)
            ->where('id', $probePk)
            ->get();

        expect($rules)->toHaveCount(1);

        // ASSERTION B2: the redesigned columns are correctly set and combinator
        // passes the enum-guard trigger.
        $rule = $rules->first();
        expect((int) $rule->priority)->toBe(55)
            ->and($rule->combinator)->toBe('all')
            ->and((int) $rule->hits_count)->toBe(0)
            ->and((bool) $rule->active)->toBeTrue();

        // ASSERTION B3: re-applying the same CREATE_ROW ops is idempotent.
        // The PK (via insertOrIgnore) prevents a duplicate row now that the old
        // UNIQUE (user_id, field, match, value) is gone.
        $replayer->replay($entries, $userId);
        $countAfter = $db->connection()
            ->table('categorization_rules')
            ->where('user_id', $userId)
            ->where('id', $probePk)
            ->count();
        expect($countAfter)->toBe(1);

        // Assert: production replayer persists CREATE_ROW ops to op_log_entries (SYNC-01).
        // RED: fails until Plan 11-02 wires op_log_entries writes in production replayer.
        $logCount = $db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->count();
        expect($logCount)->toBeGreaterThanOrEqual(5); // 5 field ops per replay (id, priority, combinator, hits_count, active), replayed twice = at least 5 total (dedup by design may reduce)
    }
);

/*
 * PROBE C: PAIR-LINK CASCADE PROBE
 *
 * FINDING: Tombstoning one side of a pair_transaction_id pair via the
 * op-log replayer causes the partner's pair_transaction_id to be set
 * NULL via the ON DELETE SET NULL FK cascade. The partner row itself is
 * NOT deleted — it remains in the ledger as a regular (unpaired) row.
 *
 * This means: after a replay that tombstones transaction A (which was
 * paired with transaction B), B.pair_transaction_id === NULL and B still
 * exists. The pair link is silently severed, not cascade-deleted.
 */
it(
    'PROBE C: tombstoning one side of a pair sets partner.pair_transaction_id=NULL (ON DELETE SET NULL) — partner row survives',
    function (): void {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        $userId = (int) $this->user->id;

        // Create two transfer transactions (pair partners).
        $txnAId = trigTxn($db, $userId, 'pair-a', $this->catA);
        $txnBId = trigTxn($db, $userId, 'pair-b', $this->catA);

        // Update transaction types for transfer pair.
        $db->connection()->table('transactions')
            ->where('id', $txnAId)
            ->update(['type' => 'transfer_out', 'pair_transaction_id' => $txnBId]);
        $db->connection()->table('transactions')
            ->where('id', $txnBId)
            ->update(['type' => 'transfer_in', 'pair_transaction_id' => $txnAId]);

        // ASSERTION C0: both rows exist and are paired.
        expect(
            $db->connection()->table('transactions')->where('id', $txnAId)->value('pair_transaction_id')
        )->toBe($txnBId);
        expect(
            $db->connection()->table('transactions')->where('id', $txnBId)->value('pair_transaction_id')
        )->toBe($txnAId);

        // Tombstone transaction A via op-log replay.
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

        // ASSERTION C1: Transaction A is deleted (tombstone wins).
        $txnAExists = $db->connection()->table('transactions')->where('id', $txnAId)->exists();
        expect($txnAExists)->toBeFalse();

        // ASSERTION C2 (FINDING): Transaction B still exists (NOT cascade-deleted).
        // ON DELETE SET NULL orphans B's pair_transaction_id without deleting B.
        $txnBExists = $db->connection()->table('transactions')->where('id', $txnBId)->exists();
        expect($txnBExists)->toBeTrue();

        // ASSERTION C3 (FINDING): B.pair_transaction_id is NULL (orphaned, not deleted).
        $txnBPairId = $db->connection()->table('transactions')->where('id', $txnBId)->value('pair_transaction_id');
        expect($txnBPairId)->toBeNull();

        // Assert: production replayer persists the DeleteTombstone op to op_log_entries (SYNC-01).
        // RED: fails until Plan 11-02 wires op_log_entries writes in production replayer.
        $logCount = $db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->count();
        expect($logCount)->toBeGreaterThanOrEqual(1);
    }
);

/*
 * SIGNATURE GATE PROOF: forged entry is not applied.
 *
 * An entry with a forged (incorrect) signature is SKIPPED by the replayer.
 * This verifies the T-10-01 mitigation: replay() verifies each entry via
 * DeviceKeySigner and skips unverifiable entries.
 */
it(
    'forged-signature entry is skipped — the DB row is not mutated (T-10-01 proof)',
    function (): void {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        $userId = (int) $this->user->id;

        $txnId = trigTxn($db, $userId, 'forged', $this->catA);

        // Confirm original category_id.
        $originalCatId = $db->connection()->table('transactions')
            ->where('id', $txnId)
            ->value('category_id');
        expect((int) $originalCatId)->toBe($this->catA);

        // Forge a signature by signing with a DIFFERENT keypair.
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
        // Sign with the WRONG secret key (not the one for 'probe-device').
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

        // Replay with 'probe-device' -> $this->pkHex (correct pubkey).
        // The forged sig (signed with forgedSk) will NOT verify against $this->pkHex.
        $replayer = new OpLogReplayer($db, $this->deviceKeys);
        $replayer->replay([$forgedEntry], $userId);

        // ASSERTION: category_id unchanged — forged entry was skipped.
        $catIdAfter = $db->connection()->table('transactions')
            ->where('id', $txnId)
            ->value('category_id');
        expect((int) $catIdAfter)->toBe($this->catA);

        // Assert: a quarantine row was written for the forged entry (D-07).
        // RED: fails until Plan 11-02 wires quarantine writes in production replayer.
        $quarantineCount = $db->connection()
            ->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->where('reason', 'forged_signature')
            ->count();
        expect($quarantineCount)->toBeGreaterThanOrEqual(1);
    }
);
