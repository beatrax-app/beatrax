<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Recurring\Public\Actions\ApproveRecurringSeries;
use Modules\Recurring\Public\Contracts\SeriesDetector;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// The detector runs on every paired device over the transactions both hold, so
// both open a series for the same merchant. Under an autoincrement id each
// minted a different one: the cluster-key UNIQUE dropped whichever create
// landed second, and that device's later approve named a pk its peer had never
// held. Seeding one device and replicating to a blank peer never showed it —
// the case is both devices detecting the same row independently.

/** @return list<string> */
function dscTables(): array
{
    return ['recurring_series', 'recurring_series_occurrences'];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');

    $this->user = User::create([
        'username' => 'series-converge-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'recurring_detection_window_months' => 36,
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->transactionIds = dscSeedLedger($db, (int) $this->user->id);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return list<int> the transaction ids both devices already hold, newest last
 */
function dscSeedLedger(DatabaseManager $db, int $userId): array
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN series converge',
        'slug' => 'series-converge-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/series-converge.csv',
        'sha256' => hash('sha256', 'series-converge-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $ids = [];

    foreach (['2026-01-04', '2026-02-04', '2026-03-04', '2026-04-04', '2026-05-04'] as $index => $postedAt) {
        $ids[] = $db->connection()->table('transactions')->insertGetId([
            'user_id' => $userId,
            'account_id' => $accountId,
            'import_run_id' => $runId,
            'type' => 'expense',
            'posted_at' => $postedAt,
            'booked_at' => $postedAt.' 12:00:00',
            'value_date' => $postedAt,
            'amount_minor' => -1099,
            'currency' => 'EUR',
            'settled_amount_minor' => -1099,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Spotify',
            'counterparty_normalized' => 'spotify',
            'normalization_version' => 3,
            'source_format' => 'asn-csv',
            'source_row_index' => $index,
            'fingerprint' => hash('sha256', 'series-converge-'.$postedAt.'-'.bin2hex(random_bytes(4))),
            'fingerprint_version' => 3,
            'created_at' => $postedAt.' 12:00:00',
            'updated_at' => $postedAt.' 12:00:00',
        ]);
    }

    return $ids;
}

// One keypair per device id, reused across rebinds: a device that signed its
// create with one key and its approve with another is two devices, and the
// replayer would rightly refuse the second signature.
function dscBindWriter(int $userId, string $deviceId): string
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

function dscWatermark(DatabaseManager $db): int
{
    $max = $db->connection()->table('op_log_entries')->max('id');

    return is_numeric($max) ? (int) $max : 0;
}

/** @return list<OpLogEntry> */
function dscOpsAfter(DatabaseManager $db, int $userId, int $afterId): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->whereIn('table_name', dscTables())
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

function dscDetect(User $user): void
{
    /** @var iterable<SeriesDetector> $detectors */
    $detectors = app()->tagged('recurring.detector');

    foreach ($detectors as $detector) {
        $detector->detectForUser($user);
    }
}

// The local rows are removed after each device's pass, so nothing the
// assertions read can have come from anywhere but the replay.
function dscForgetDetectorRows(DatabaseManager $db, int $userId): void
{
    $db->connection()->table('recurring_series_occurrences')->where('user_id', $userId)->delete();
    $db->connection()->table('recurring_series_transitions')->where('user_id', $userId)->delete();
    $db->connection()->table('recurring_series')->where('user_id', $userId)->delete();
}

/**
 * @param  array<string, string>  $deviceKeys
 * @param  list<OpLogEntry>  $ops
 */
function dscReplay(DatabaseManager $db, array $deviceKeys, array $ops, int $userId): void
{
    (new OpLogReplayer($db, $deviceKeys, new MergeRulesRegistry))->replay($ops, $userId);
}

/**
 * @return array{0: string, 1: list<OpLogEntry>}
 */
function dscDetectOnDevice(DatabaseManager $db, User $user, string $deviceId): array
{
    $userId = (int) $user->id;
    $key = dscBindWriter($userId, $deviceId);
    $watermark = dscWatermark($db);

    dscDetect($user);

    $ops = dscOpsAfter($db, $userId, $watermark);
    dscForgetDetectorRows($db, $userId);

    return [$key, $ops];
}

function dscDerivedSeriesId(int $userId): int
{
    return DerivedRowId::for('recurring_series', [
        'user_id' => $userId,
        'direction' => 'expense',
        'cluster_counterparty_key' => 'spotify',
        'latest_currency' => 'EUR',
    ]);
}

it('gives two devices the same series id for the same merchant', function (): void {
    $userId = (int) $this->user->id;

    dscDetect($this->user);
    $onPhone = (int) $this->db->connection()->table('recurring_series')->where('user_id', $userId)->value('id');
    dscForgetDetectorRows($this->db, $userId);

    dscDetect($this->user);
    $onDesktop = (int) $this->db->connection()->table('recurring_series')->where('user_id', $userId)->value('id');

    expect($onPhone)->toBe($onDesktop)
        ->and($onPhone)->toBe(dscDerivedSeriesId($userId))
        ->and($onPhone)->toBeGreaterThan(0);

    // A second merchant is a second series, so the identity has to separate
    // them — otherwise convergence would be indistinguishable from collapsing
    // every series this reader has onto one row.
    expect(DerivedRowId::for('recurring_series', [
        'user_id' => $userId,
        'direction' => 'expense',
        'cluster_counterparty_key' => 'netflix',
        'latest_currency' => 'EUR',
    ]))->not->toBe($onPhone);
});

it('collapses two devices detecting the same series into one row, and lands a later approve on it', function (): void {
    $userId = (int) $this->user->id;
    $seriesId = dscDerivedSeriesId($userId);

    [$phoneKey, $phoneOps] = dscDetectOnDevice($this->db, $this->user, 'device-phone');
    [$desktopKey, $desktopOps] = dscDetectOnDevice($this->db, $this->user, 'device-desktop');

    expect($phoneOps)->not->toBeEmpty('the phone detected a series and captured nothing')
        ->and($desktopOps)->not->toBeEmpty('the desktop detected a series and captured nothing');

    $deviceKeys = ['device-phone' => $phoneKey, 'device-desktop' => $desktopKey];
    dscReplay($this->db, $deviceKeys, [...$phoneOps, ...$desktopOps], $userId);

    expect($this->db->connection()->table('recurring_series')->where('user_id', $userId)->count())->toBe(1)
        ->and($this->db->connection()->table('recurring_series')->where('id', $seriesId)->exists())->toBeTrue()
        ->and($this->db->connection()->table('recurring_series_occurrences')->where('user_id', $userId)->count())
        ->toBe(count($this->transactionIds));

    // The desktop then approves it. Under the old autoincrement this SET named
    // the id the desktop had minted locally, which the phone's create had
    // already displaced — the edit landed on nothing.
    dscBindWriter($userId, 'device-desktop');
    $watermark = dscWatermark($this->db);

    app(ApproveRecurringSeries::class)($seriesId, $this->user);

    $approveOps = dscOpsAfter($this->db, $userId, $watermark);
    expect($approveOps)->not->toBeEmpty('approving a series captured nothing');

    dscForgetDetectorRows($this->db, $userId);

    dscReplay($this->db, $deviceKeys, [...$phoneOps, ...$desktopOps, ...$approveOps], $userId);

    $rows = $this->db->connection()->table('recurring_series')->where('user_id', $userId)->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->id)->toBe($seriesId)
        ->and((string) $rows[0]->state)->toBe('approved')
        ->and($this->db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0);
});

it('gives two devices the same occurrence id for the same charge', function (): void {
    $userId = (int) $this->user->id;
    $seriesId = dscDerivedSeriesId($userId);

    [$phoneKey, $phoneOps] = dscDetectOnDevice($this->db, $this->user, 'device-phone');
    [$desktopKey, $desktopOps] = dscDetectOnDevice($this->db, $this->user, 'device-desktop');

    $occurrenceOpPks = static fn (array $ops): array => array_values(array_unique(array_map(
        static fn (OpLogEntry $entry): int|string => $entry->pk,
        array_filter($ops, static fn (OpLogEntry $entry): bool => $entry->table === 'recurring_series_occurrences'),
    )));

    $phonePks = $occurrenceOpPks($phoneOps);
    sort($phonePks);
    $desktopPks = $occurrenceOpPks($desktopOps);
    sort($desktopPks);

    expect($phonePks)->toHaveCount(count($this->transactionIds))
        ->and($phonePks)->toBe($desktopPks);

    dscReplay($this->db, ['device-phone' => $phoneKey, 'device-desktop' => $desktopKey], [...$phoneOps, ...$desktopOps], $userId);

    $stored = $this->db->connection()->table('recurring_series_occurrences')
        ->where('recurring_series_id', $seriesId)
        ->orderBy('id')
        ->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->all();
    sort($stored);

    expect($stored)->toBe($phonePks)
        ->and($this->db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0);
});
