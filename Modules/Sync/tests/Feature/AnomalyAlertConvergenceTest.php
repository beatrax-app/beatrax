<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DeviceMintedRowId;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Public\Events\EntityMutated;

uses(RefreshDatabase::class);

// The detector runs on every paired device, so both open an alert for the same
// charge. Under an autoincrement id each took a different one, the UNIQUE on
// transaction_id dropped whichever create landed second, and that device's
// later acknowledge named a pk its peer had never held.

// The id was briefly folded from (user_id, transaction_id) so the two would
// agree. They did not: a charge's id is a number each device counts for itself,
// so the fold named a different charge on the peer. The id is minted now, the
// two devices never agree on it, and `anomaly_alerts_uniq` is what makes them
// one row — the arriving create's transaction_id translated, the index refusing
// it, the peer's id remembered against the local row.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-20 09:00:00');

    $this->user = User::create([
        'username' => 'anomaly-converge-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function anomalyConvergeTransaction(DatabaseManager $db, int $userId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN anomaly converge',
        'slug' => 'anomaly-converge-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-08-01 00:00:00',
        'updated_at' => '2026-08-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/anomaly-converge.csv',
        'sha256' => hash('sha256', 'anomaly-converge-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-08-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-08-01 00:00:00',
        'updated_at' => '2026-08-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'anomaly-converge-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-08-04',
        'booked_at' => '2026-08-04 10:00:00',
        'value_date' => '2026-08-04',
        'amount_minor' => -23490,
        'currency' => 'EUR',
        'settled_amount_minor' => -23490,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'unusual merchant',
        'counterparty_name' => 'Unusual Merchant',
        'normalization_version' => 3,
        'description' => 'anomaly convergence fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-08-04 00:00:00',
        'updated_at' => '2026-08-04 00:00:00',
    ]);
}

function anomalyConvergeBindWriter(int $userId, string $deviceId): string
{
    // One keypair per device id, reused across rebinds: a device that signed
    // its create with one key and its acknowledge with another is two devices,
    // and the replayer would rightly refuse the second signature.
    static $keypairs = [];
    $keypairs[$deviceId] ??= sodium_crypto_sign_keypair();

    $keypair = $keypairs[$deviceId];
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => $secret,
        'publicKey' => $public,
    ]);
    app()->instance(OpLogWriter::class, $writer);

    return bin2hex($public);
}

function anomalyConvergeMaxOpLogId(DatabaseManager $db): int
{
    $max = $db->connection()->table('op_log_entries')->max('id');

    return is_numeric($max) ? (int) $max : 0;
}

/** @return list<OpLogEntry> */
function anomalyConvergeOpsAfter(DatabaseManager $db, int $userId, int $afterId): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'anomaly_alerts')
        ->where('id', '>', $afterId)
        ->orderBy('id')
        ->get()
        ->map(static fn (object $row): OpLogEntry => new OpLogEntry(
            table: (string) $row->table_name,
            pk: is_numeric($row->pk) ? (int) $row->pk : (string) $row->pk,
            field: (string) $row->field,
            value: $row->value !== null ? (string) $row->value : null,
            hlcL: (int) $row->hlc_l,
            hlcC: (int) $row->hlc_c,
            deviceId: (string) $row->device_id,
            opType: OpType::from((string) $row->op_type),
            signature: (string) $row->signature,
            userId: (int) $row->user_id,
        ))
        ->all();
}

// The local row is removed afterwards, so the assertions can only be satisfied
// by what replay rebuilds.
/**
 * @return array{0: string, 1: list<OpLogEntry>, 2: int}
 */
function anomalyConvergeOpenOnDevice(DatabaseManager $db, int $userId, string $deviceId, int $transactionId): array
{
    $devicePublicKey = anomalyConvergeBindWriter($userId, $deviceId);
    $watermark = anomalyConvergeMaxOpLogId($db);

    $alertId = DeviceMintedRowId::mint();

    $row = [
        'user_id' => $userId,
        'transaction_id' => $transactionId,
        'state' => 'open',
        'direction' => 'expense',
        'reasons' => json_encode(['large']),
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -23490,
        'currency' => 'EUR',
        'sensitivity_percent_used' => 50,
        'detected_at' => '2026-08-04 10:05:00',
        'created_at' => '2026-08-04 10:05:00',
        'updated_at' => '2026-08-04 10:05:00',
    ];

    $db->connection()->table('anomaly_alerts')->insert(['id' => $alertId] + $row);

    app(Dispatcher::class)->dispatch(new EntityMutated(
        table: 'anomaly_alerts',
        pk: $alertId,
        userId: $userId,
        mutationType: 'create',
        dirtyFields: $row,
    ));

    $ops = anomalyConvergeOpsAfter($db, $userId, $watermark);
    $db->connection()->table('anomaly_alerts')->where('id', $alertId)->delete();

    return [$devicePublicKey, $ops, $alertId];
}

it('gives one row to the charge two devices each opened an alert on', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) $this->user->id;
    $transactionId = anomalyConvergeTransaction($db, $userId);

    [$phoneKey, $phoneOps, $onPhone] = anomalyConvergeOpenOnDevice($db, $userId, 'device-phone', $transactionId);
    [$desktopKey, $desktopOps, $onDesktop] = anomalyConvergeOpenOnDevice($db, $userId, 'device-desktop', $transactionId);

    expect($onPhone)->not->toBe($onDesktop)
        ->and($onPhone)->toBeGreaterThan(0)
        ->and($onDesktop)->toBeLessThanOrEqual(PHP_INT_MAX)
        ->and($phoneOps)->not->toBeEmpty()
        ->and($desktopOps)->not->toBeEmpty();

    $replayer = new OpLogReplayer(
        $db,
        ['device-phone' => $phoneKey, 'device-desktop' => $desktopKey],
        new MergeRulesRegistry,
    );
    $replayer->replay([...$phoneOps, ...$desktopOps], $userId);

    $alerts = $db->connection()->table('anomaly_alerts')->where('user_id', $userId)->get();
    $aliases = $db->connection()->table('op_log_row_aliases')
        ->where('user_id', $userId)
        ->where('table_name', 'anomaly_alerts')
        ->count();

    // A different charge is a different alert, so a second one must not fold
    // onto this row — otherwise convergence would be indistinguishable from
    // collapsing every alert this reader has onto one.
    $otherTransactionId = anomalyConvergeTransaction($db, $userId);
    [, $otherOps] = anomalyConvergeOpenOnDevice($db, $userId, 'device-phone', $otherTransactionId);
    $replayer->replay($otherOps, $userId);

    expect($alerts)->toHaveCount(1)
        ->and((int) $alerts[0]->transaction_id)->toBe($transactionId)
        ->and($aliases)->toBe(1)
        ->and($db->connection()->table('anomaly_alerts')->where('user_id', $userId)->count())->toBe(2)
        ->and($db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0);
});

it('collapses two devices opening the same alert into one row, and lands a later acknowledge on it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) $this->user->id;
    $transactionId = anomalyConvergeTransaction($db, $userId);

    [$phoneKey, $phoneOps, $onPhone] = anomalyConvergeOpenOnDevice($db, $userId, 'device-phone', $transactionId);
    [$desktopKey, $desktopOps, $onDesktop] = anomalyConvergeOpenOnDevice($db, $userId, 'device-desktop', $transactionId);

    expect($phoneOps)->not->toBeEmpty()->and($desktopOps)->not->toBeEmpty();

    // The desktop then acknowledges it, naming the id IT holds. The phone's
    // create is the one that lands first, so the SET has to reach that row
    // through the alias rather than through a number the two ever shared.
    anomalyConvergeBindWriter($userId, 'device-desktop');
    $watermark = anomalyConvergeMaxOpLogId($db);

    app(Dispatcher::class)->dispatch(new EntityMutated(
        table: 'anomaly_alerts',
        pk: $onDesktop,
        userId: $userId,
        mutationType: 'edit',
        dirtyFields: ['state' => 'acknowledged', 'actioned_at' => '2026-08-05 08:00:00'],
    ));

    $acknowledgeOps = anomalyConvergeOpsAfter($db, $userId, $watermark);
    expect($acknowledgeOps)->not->toBeEmpty();

    $replayer = new OpLogReplayer(
        $db,
        ['device-phone' => $phoneKey, 'device-desktop' => $desktopKey],
        new MergeRulesRegistry,
    );
    $replayer->replay([...$phoneOps, ...$desktopOps, ...$acknowledgeOps], $userId);

    $alert = $db->connection()->table('anomaly_alerts')->where('user_id', $userId)->first();

    expect($db->connection()->table('anomaly_alerts')->where('user_id', $userId)->count())->toBe(1)
        ->and($alert)->not->toBeNull()
        ->and((int) $alert->id)->toBe($onPhone)
        ->and((int) $alert->transaction_id)->toBe($transactionId)
        ->and((string) $alert->state)->toBe('acknowledged')
        ->and((string) $alert->actioned_at)->toBe('2026-08-05 08:00:00')
        ->and($db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0);
});
