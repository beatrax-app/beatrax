<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Public\Events\TransactionMutated;

uses(RefreshDatabase::class);

/*
 * Wave 0 stub for SC-3/D-10 (13.3-VALIDATION.md), mirroring
 * EnvelopeConcurrentEditConvergenceTest's shape.
 *
 * Per D-10/RESEARCH.md, `SyncCaptureListener`/`OpLogReplayer` are already
 * fully field-agnostic for the `transactions` table — capturing/replaying
 * an arbitrary `status` edit needs zero listener changes, and
 * `MergeRulesRegistry::strategyFor()` already defaults an unregistered
 * field to 'lww'. This test may therefore already be GREEN before 13.3-02
 * lands the explicit registry line; its role is to PIN the convergence
 * contract as a regression guard for the real toggle/reconcile write paths
 * (13.3-02/03/04), not to gate on new production code — mirroring
 * StatusSurvivesReimportTest's already-green role for D-04.
 *
 * Scenario: one transaction is seeded 'cleared' -- both "device A" and
 * "device B" hold an IDENTICAL row (same stable PK). Offline (no
 * cross-sync yet): device A sets status to 'uncleared'; device B (unaware
 * of A's edit) independently sets the SAME field to a DIFFERENT value,
 * 'reconciled'. Both devices' real, captured op-log entries are merged and
 * replayed. Per-(table,pk,field) LWW must converge DETERMINISTICALLY --
 * replaying the merged op set in either application order must yield the
 * IDENTICAL final state.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-04 11:00:00');

    $this->user = User::create([
        'username' => 'status-concurrent-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'status-concurrent-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.bin2hex(random_bytes(6)),
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/status-concurrent.xml',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->tx = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-01',
        'booked_at' => '2026-07-01 12:00:00',
        'value_date' => '2026-07-01',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Fixture merchant',
        'counterparty_normalized' => 'fixture merchant',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 1,
        'fingerprint' => str_repeat('b', 64),
        'fingerprint_version' => 1,
        'status' => 'cleared',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * Constructs a fresh OpLogWriter for the given device, binds it into the
 * container (so SyncCaptureListener's lazy resolution picks it up), and
 * returns its hex-encoded public key for the replayer's device-key map.
 */
function bindStatusDeviceWriter(int $userId, string $deviceId): string
{
    $keypair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($keypair);
    $pk = sodium_crypto_sign_publickey($keypair);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => $sk,
        'publicKey' => $pk,
    ]);
    app()->instance(OpLogWriter::class, $writer);

    return bin2hex($pk);
}

/**
 * @return list<OpLogEntry>
 */
function statusOpsAfter(DatabaseManager $db, int $userId, int $afterId): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'transactions')
        ->where('field', 'status')
        ->where('id', '>', $afterId)
        ->orderBy('id')
        ->get()
        ->map(static function (object $row): OpLogEntry {
            $pk = is_numeric($row->pk) ? (int) $row->pk : (string) $row->pk;

            return new OpLogEntry(
                table: (string) $row->table_name,
                pk: $pk,
                field: (string) $row->field,
                value: $row->value !== null ? (string) $row->value : null,
                hlcL: (int) $row->hlc_l,
                hlcC: (int) $row->hlc_c,
                deviceId: (string) $row->device_id,
                opType: OpType::from((string) $row->op_type),
                signature: (string) $row->signature,
                userId: (int) $row->user_id,
            );
        })
        ->all();
}

function statusMaxOpLogId(DatabaseManager $db): int
{
    $max = $db->connection()->table('op_log_entries')->max('id');

    return is_numeric($max) ? (int) $max : 0;
}

it('two devices concurrently editing the SAME transaction status field converge deterministically regardless of replay order', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // --- Device A (offline): sets status to 'uncleared'. ---
    $pkA = bindStatusDeviceWriter((int) $this->user->id, 'device-a');
    $watermarkA = statusMaxOpLogId($db);
    $db->connection()->table('transactions')->where('id', $this->tx->id)->update(['status' => 'uncleared']);
    app(Dispatcher::class)->dispatch(new TransactionMutated(
        transactionId: $this->tx->id,
        userId: (int) $this->user->id,
        mutationType: 'edit',
        dirtyFields: ['status' => 'uncleared'],
    ));
    $aOps = statusOpsAfter($db, (int) $this->user->id, $watermarkA);
    expect($aOps)->not->toBeEmpty();

    // Restore the live row to its ORIGINAL value -- device B is offline and
    // has NOT seen A's edit (scratch-state manipulation only; the final
    // converged state is entirely determined by replaying the merged ops).
    $db->connection()->table('transactions')->where('id', $this->tx->id)->update(['status' => 'cleared']);

    // --- Device B (offline, independently): sets the SAME field to a DIFFERENT value. ---
    $pkB = bindStatusDeviceWriter((int) $this->user->id, 'device-b');
    $watermarkB = statusMaxOpLogId($db);
    $db->connection()->table('transactions')->where('id', $this->tx->id)->update(['status' => 'reconciled']);
    app(Dispatcher::class)->dispatch(new TransactionMutated(
        transactionId: $this->tx->id,
        userId: (int) $this->user->id,
        mutationType: 'edit',
        dirtyFields: ['status' => 'reconciled'],
    ));
    $bOps = statusOpsAfter($db, (int) $this->user->id, $watermarkB);
    expect($bOps)->not->toBeEmpty();

    $deviceKeys = ['device-a' => $pkA, 'device-b' => $pkB];

    // Replay in one order.
    $replayerForward = new OpLogReplayer($db, $deviceKeys, new MergeRulesRegistry);
    $replayerForward->replay([...$aOps, ...$bOps], (int) $this->user->id);
    $forwardResult = (string) $db->connection()->table('transactions')->where('id', $this->tx->id)->value('status');

    // Reset and replay in the REVERSE order -- LWW must converge to the SAME value.
    $db->connection()->table('transactions')->where('id', $this->tx->id)->update(['status' => 'cleared']);
    $replayerReverse = new OpLogReplayer($db, $deviceKeys, new MergeRulesRegistry);
    $replayerReverse->replay([...$bOps, ...$aOps], (int) $this->user->id);
    $reverseResult = (string) $db->connection()->table('transactions')->where('id', $this->tx->id)->value('status');

    expect($reverseResult)->toBe($forwardResult);
    expect(['uncleared', 'reconciled'])->toContain($forwardResult);

    // Exactly one row -- no duplicate/lost transaction.
    expect($db->connection()->table('transactions')->where('id', $this->tx->id)->count())->toBe(1);

    // No quarantine -- both devices' keys were known, signatures verified,
    // and every _create_required/strategy path resolved cleanly.
    expect($db->connection()->table('op_log_quarantine')->where('user_id', $this->user->id)->count())->toBe(0);
});
