<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\DriftAlerts\Public\Http\Livewire\SavingsInsightsCard;
use Modules\DriftAlerts\Public\Services\SavingsInsightsQuery;
use Modules\Recurring\Public\Actions\ApproveRecurringSeries;
use Modules\Recurring\Public\Contracts\SeriesDetector;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// A "Ways to save" dismissal is keyed "{kind}:{recurring_series_id}", so it was
// blocked twice over: nothing captured it, and the series id it names was a
// number each device minted for itself. Waving the card away on the desktop
// left it on the phone's dashboard.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');

    $this->user = User::create([
        'username' => 'savings-converge-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'recurring_detection_window_months' => 36,
    ]);
    $this->actingAs($this->user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    dsdSeedSpotify($db, (int) $this->user->id);
    dsdDetect($this->user);

    $this->seriesId = (int) $db->connection()->table('recurring_series')->where('user_id', $this->user->id)->value('id');
    app(ApproveRecurringSeries::class)($this->seriesId, $this->user);

    Cache::flush();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function dsdSeedSpotify(DatabaseManager $db, int $userId): void
{
    $counterpartyId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => 'spotify-'.bin2hex(random_bytes(3)),
        'display_name' => 'Spotify',
        'merchant_name' => 'Spotify',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN savings converge',
        'slug' => 'savings-converge-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/savings-converge.csv',
        'sha256' => hash('sha256', 'savings-converge-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    foreach (['2026-03-04', '2026-04-04', '2026-05-04'] as $postedAt) {
        $db->connection()->table('transactions')->insert([
            'user_id' => $userId,
            'account_id' => $accountId,
            'import_run_id' => $runId,
            'counterparty_id' => $counterpartyId,
            'type' => 'expense',
            'posted_at' => $postedAt,
            'booked_at' => $postedAt.' 12:00:00',
            'value_date' => $postedAt,
            'amount_minor' => -999,
            'currency' => 'EUR',
            'settled_amount_minor' => -999,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Spotify',
            'counterparty_normalized' => 'spotify',
            'normalization_version' => 3,
            'source_format' => 'asn-csv',
            'source_row_index' => crc32($postedAt) % 100000,
            'fingerprint' => hash('sha256', 'savings-converge-'.$postedAt.'-'.bin2hex(random_bytes(4))),
            'fingerprint_version' => 3,
            'created_at' => $postedAt.' 12:00:00',
            'updated_at' => $postedAt.' 12:00:00',
        ]);
    }
}

function dsdDetect(User $user): void
{
    /** @var iterable<SeriesDetector> $detectors */
    $detectors = app()->tagged('recurring.detector');

    foreach ($detectors as $detector) {
        $detector->detectForUser($user);
    }
}

function dsdBindWriter(int $userId, string $deviceId): string
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

/** @return list<OpLogEntry> */
function dsdOpsAfter(DatabaseManager $db, int $userId, int $afterId): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'savings_insight_dismissals')
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

it('keeps a Ways to save card dismissed on the desktop dismissed on the phone', function (): void {
    $userId = (int) $this->user->id;
    $insights = app(SavingsInsightsQuery::class)->forUser($this->user);

    expect($insights)->toHaveCount(1)
        ->and($insights[0]->key)->toBe('cheaper:'.$this->seriesId);

    $key = $insights[0]->key;

    $desktopKey = dsdBindWriter($userId, 'device-desktop');
    $watermark = (int) $this->db->connection()->table('op_log_entries')->max('id');

    Livewire::test(SavingsInsightsCard::class)
        ->assertSee('Ways to save')
        ->call('dismiss', $key)
        ->assertDontSee('Ways to save');

    $ops = dsdOpsAfter($this->db, $userId, $watermark);
    expect($ops)->not->toBeEmpty('dismissing a savings card captured nothing');

    // The key embeds the series id, so the pk is only reachable by a peer that
    // derived the same series id in the first place.
    $dismissalId = DerivedRowId::for('savings_insight_dismissals', [
        'user_id' => $userId,
        'insight_key' => $key,
    ]);

    expect(array_values(array_unique(array_map(
        static fn (OpLogEntry $entry): int|string => $entry->pk,
        $ops,
    ))))->toBe([$dismissalId]);

    // The phone: same series, same card, and no dismissal of its own.
    $this->db->connection()->table('savings_insight_dismissals')->where('user_id', $userId)->delete();
    Cache::flush();

    Livewire::test(SavingsInsightsCard::class)->assertSee('Ways to save');

    (new OpLogReplayer($this->db, ['device-desktop' => $desktopKey], new MergeRulesRegistry))->replay($ops, $userId);
    Cache::flush();

    expect($this->db->connection()->table('savings_insight_dismissals')->where('id', $dismissalId)->exists())->toBeTrue()
        ->and(app(SavingsInsightsQuery::class)->forUser($this->user))->toBe([])
        ->and($this->db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0);

    Livewire::test(SavingsInsightsCard::class)->assertDontSee('Ways to save');
});
