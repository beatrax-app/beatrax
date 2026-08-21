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

// Capture and replay are field-agnostic for transactions, and strategyFor()
// defaults an unregistered field to lww, so this converges whether or not
// `status` has an explicit registry line: it pins the convergence contract as a
// regression guard on the toggle and reconcile write paths, not as a gate.

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

// Bound into the container because SyncCaptureListener resolves its writer lazily.
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

    // Back to the original value: device B is offline and has not seen A's edit.
    // Only the merged replay below decides the converged state.
    $db->connection()->table('transactions')->where('id', $this->tx->id)->update(['status' => 'cleared']);

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

    $replayerForward = new OpLogReplayer($db, $deviceKeys, new MergeRulesRegistry);
    $replayerForward->replay([...$aOps, ...$bOps], (int) $this->user->id);
    $forwardResult = (string) $db->connection()->table('transactions')->where('id', $this->tx->id)->value('status');

    $db->connection()->table('transactions')->where('id', $this->tx->id)->update(['status' => 'cleared']);
    $replayerReverse = new OpLogReplayer($db, $deviceKeys, new MergeRulesRegistry);
    $replayerReverse->replay([...$bOps, ...$aOps], (int) $this->user->id);
    $reverseResult = (string) $db->connection()->table('transactions')->where('id', $this->tx->id)->value('status');

    expect($reverseResult)->toBe($forwardResult);
    expect(['uncleared', 'reconciled'])->toContain($forwardResult);

    expect($db->connection()->table('transactions')->where('id', $this->tx->id)->count())->toBe(1);

    // Anything in quarantine would mean an unknown device key, a signature that
    // did not verify, or a strategy that did not resolve.
    expect($db->connection()->table('op_log_quarantine')->where('user_id', $this->user->id)->count())->toBe(0);
});
