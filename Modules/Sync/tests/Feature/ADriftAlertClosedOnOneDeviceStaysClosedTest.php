<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\DriftAlerts\Public\Actions\DismissDriftAlertAsCancelled;
use Modules\Recurring\Public\Actions\ApproveRecurringSeries;
use Modules\Recurring\Public\Contracts\SeriesDetector;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// The reader tells the desktop they cancelled the subscription. The phone's own
// detector runs the next morning over the same charges, and until both devices
// derived the alert's id — and the occurrence id it is derived FROM — it opened
// the alert again and synced it back, so a subscription dismissed once kept
// re-appearing on the other screen.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');

    $this->user = User::create([
        'username' => 'drift-converge-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'recurring_detection_window_months' => 36,
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    [$this->accountId, $this->runId] = dacSeedLedger($db, (int) $this->user->id);

    foreach (['2026-02-04', '2026-03-04', '2026-04-04'] as $postedAt) {
        dacCharge($db, (int) $this->user->id, $this->accountId, $this->runId, $postedAt, -1099);
    }

    dacDetect($this->user);

    $this->seriesId = (int) $db->connection()->table('recurring_series')->where('user_id', $this->user->id)->value('id');
    app(ApproveRecurringSeries::class)($this->seriesId, $this->user);

    // The price rise both devices are about to notice, nine percent over a
    // five-percent default threshold.
    dacCharge($db, (int) $this->user->id, $this->accountId, $this->runId, '2026-05-04', -1199);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array{0: int, 1: int}
 */
function dacSeedLedger(DatabaseManager $db, int $userId): array
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN drift converge',
        'slug' => 'drift-converge-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/drift-converge.csv',
        'sha256' => hash('sha256', 'drift-converge-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    return [$accountId, $runId];
}

function dacCharge(DatabaseManager $db, int $userId, int $accountId, int $runId, string $postedAt, int $amountMinor): int
{
    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Spotify',
        'counterparty_normalized' => 'spotify',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'source_row_index' => crc32($postedAt) % 100000,
        'fingerprint' => hash('sha256', 'drift-converge-'.$postedAt.'-'.bin2hex(random_bytes(4))),
        'fingerprint_version' => 3,
        'created_at' => $postedAt.' 12:00:00',
        'updated_at' => $postedAt.' 12:00:00',
    ]);
}

function dacDetect(User $user): void
{
    /** @var iterable<SeriesDetector> $detectors */
    $detectors = app()->tagged('recurring.detector');

    foreach ($detectors as $detector) {
        $detector->detectForUser($user);
    }
}

function dacBindWriter(int $userId, string $deviceId): string
{
    static $keypairs = [];
    $keypairs[$deviceId] ??= sodium_crypto_sign_keypair();

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypairs[$deviceId]),
        'publicKey' => sodium_crypto_sign_publickey($keypairs[$deviceId]),
    ]);
    app()->instance(OpLogWriter::class, $writer);

    return bin2hex(sodium_crypto_sign_publickey($keypairs[$deviceId]));
}

function dacWatermark(DatabaseManager $db): int
{
    $max = $db->connection()->table('op_log_entries')->max('id');

    return is_numeric($max) ? (int) $max : 0;
}

/** @return list<OpLogEntry> */
function dacOpsAfter(DatabaseManager $db, int $userId, int $afterId): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'drift_alerts')
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

function dacForgetAlerts(DatabaseManager $db, int $userId): void
{
    $db->connection()->table('drift_alert_transitions')->where('user_id', $userId)->delete();
    $db->connection()->table('drift_alerts')->where('user_id', $userId)->delete();
}

/**
 * @return array{0: string, 1: list<OpLogEntry>, 2: int}
 */
function dacRaiseOnDevice(DatabaseManager $db, User $user, string $deviceId): array
{
    $userId = (int) $user->id;
    $key = dacBindWriter($userId, $deviceId);
    $watermark = dacWatermark($db);

    dacDetect($user);

    $alertId = (int) $db->connection()->table('drift_alerts')->where('user_id', $userId)->value('id');
    $ops = dacOpsAfter($db, $userId, $watermark);
    dacForgetAlerts($db, $userId);

    return [$key, $ops, $alertId];
}

it('gives two devices the same drift alert id for the same price rise', function (): void {
    $userId = (int) $this->user->id;

    [, $phoneOps, $onPhone] = dacRaiseOnDevice($this->db, $this->user, 'device-phone');
    [, $desktopOps, $onDesktop] = dacRaiseOnDevice($this->db, $this->user, 'device-desktop');

    $latestOccurrenceId = (int) $this->db->connection()->table('recurring_series_occurrences')
        ->where('recurring_series_id', $this->seriesId)
        ->orderByDesc('observed_at')
        ->value('id');

    expect($onPhone)->toBeGreaterThan(0)
        ->and($onPhone)->toBe($onDesktop)
        ->and($onPhone)->toBe(DerivedRowId::for('drift_alerts', [
            'recurring_series_id' => $this->seriesId,
            'latest_occurrence_id' => $latestOccurrenceId,
        ]))
        ->and($phoneOps)->not->toBeEmpty('the phone raised a drift alert and captured nothing')
        ->and($desktopOps)->not->toBeEmpty('the desktop raised a drift alert and captured nothing');

    // Next month's rise on the same series is a different alert, so the
    // identity has to separate them.
    expect(DerivedRowId::for('drift_alerts', [
        'recurring_series_id' => $this->seriesId,
        'latest_occurrence_id' => $latestOccurrenceId + 1,
    ]))->not->toBe($onPhone);
});

it('does not re-raise on the phone a drift alert the desktop dismissed', function (): void {
    $userId = (int) $this->user->id;

    [$phoneKey, $phoneOps, $alertId] = dacRaiseOnDevice($this->db, $this->user, 'device-phone');
    [$desktopKey, $desktopOps] = dacRaiseOnDevice($this->db, $this->user, 'device-desktop');

    $deviceKeys = ['device-phone' => $phoneKey, 'device-desktop' => $desktopKey];
    $replay = fn (array $ops) => (new OpLogReplayer($this->db, $deviceKeys, new MergeRulesRegistry))->replay($ops, $userId);

    $replay([...$phoneOps, ...$desktopOps]);

    expect($this->db->connection()->table('drift_alerts')->where('user_id', $userId)->count())->toBe(1)
        ->and($this->db->connection()->table('drift_alerts')->where('id', $alertId)->exists())->toBeTrue();

    // The reader cancels the subscription and says so on the desktop.
    dacBindWriter($userId, 'device-desktop');
    $watermark = dacWatermark($this->db);

    app(DismissDriftAlertAsCancelled::class)($alertId, $this->user);

    $dismissOps = dacOpsAfter($this->db, $userId, $watermark);
    expect($dismissOps)->not->toBeEmpty('dismissing a drift alert captured nothing');

    // The phone: its own alert, then the desktop's dismissal arriving.
    dacForgetAlerts($this->db, $userId);
    $replay([...$phoneOps, ...$desktopOps, ...$dismissOps]);

    $alert = $this->db->connection()->table('drift_alerts')->where('user_id', $userId)->first();

    expect($alert)->not->toBeNull()
        ->and((int) $alert->id)->toBe($alertId)
        ->and((string) $alert->state)->toBe('dismissed_cancelled');

    // The phone's next sweep. It re-evaluates the same series over the same
    // charges, and the row it would open is the row it already holds.
    dacBindWriter($userId, 'device-phone');
    dacDetect($this->user);

    $rows = $this->db->connection()->table('drift_alerts')->where('user_id', $userId)->get();

    expect($rows)->toHaveCount(1)
        ->and((string) $rows[0]->state)->toBe('dismissed_cancelled')
        ->and($this->db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0);
});
