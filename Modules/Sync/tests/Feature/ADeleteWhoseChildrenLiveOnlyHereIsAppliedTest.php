<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Public\Services\HistoryReprojector;

uses(RefreshDatabase::class);

// A scenario made on the desktop syncs to the phone, the phone's own scheduled
// projection writes forecast_runs behind it, and the desktop then deletes the
// scenario. Those runs are rows the desktop has never heard of, so no op it
// can write names them: the foreign key refuses the tombstone, the entry is
// filed beside a forged signature, and nothing ever looks at it again. The two
// devices disagree about the scenario for the rest of the install's life.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'device-local-child-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->userId = (int) $this->user->id;

    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->signer = new DeviceKeySigner;
    $this->deviceKeys = ['desktop-device' => bin2hex(sodium_crypto_sign_publickey($keypair))];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function deviceLocalChildTombstone(DeviceKeySigner $signer, string $sk, int $userId, string $table, int $pk): OpLogEntry
{
    $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
        table: $table,
        pk: $pk,
        field: '',
        value: null,
        hlcL: 1_800_000_000_000,
        hlcC: 0,
        deviceId: 'desktop-device',
        opType: OpType::DeleteTombstone,
        signature: $signature,
        userId: $userId,
    );

    return $make($signer->sign($make('')->signingPayload(), $sk));
}

function deviceLocalChildScenario(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('forecast_scenarios')->insertGetId([
        'user_id' => $userId,
        'name' => 'Sabbatical '.bin2hex(random_bytes(4)),
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function deviceLocalChildAccount(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Card',
        'slug' => 'card-'.bin2hex(random_bytes(4)),
        'kind' => 'card',
        'iban' => 'NL00CARD'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function deviceLocalChildReplay(DatabaseManager $db, array $deviceKeys, OpLogEntry $tombstone, int $userId): void
{
    (new OpLogReplayer($db, $deviceKeys))->replay([$tombstone], $userId);
}

function deviceLocalChildBlockedHolds(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('reason', QuarantineReason::DeleteBlockedByReference->value)
        ->count();
}

it('applies a tombstone whose only blocker is a row this device derived for itself', function (): void {
    $scenarioId = deviceLocalChildScenario($this->db, $this->userId);

    $runId = (int) $this->db->connection()->table('forecast_runs')->insertGetId([
        'user_id' => $this->userId,
        'scenario_id' => $scenarioId,
        'horizon_days' => 90,
        'status' => 'succeeded',
        'started_at' => '2026-06-13 03:00:00',
        'completed_at' => '2026-06-13 03:00:04',
        'created_at' => '2026-06-13 03:00:00',
        'updated_at' => '2026-06-13 03:00:04',
    ]);

    deviceLocalChildReplay(
        $this->db,
        $this->deviceKeys,
        deviceLocalChildTombstone($this->signer, $this->sk, $this->userId, 'forecast_scenarios', $scenarioId),
        $this->userId,
    );

    expect($this->db->connection()->table('forecast_scenarios')->where('id', $scenarioId)->exists())->toBeFalse()
        ->and($this->db->connection()->table('forecast_runs')->where('id', $runId)->exists())->toBeFalse()
        ->and(deviceLocalChildBlockedHolds($this->db, $this->userId))->toBe(0);
});

// The walk is not one table deep. A card statement is derived here from an
// import that never left this device, and it owns its own credits.
it('takes a grandchild that only this device has along with the child', function (): void {
    $accountId = deviceLocalChildAccount($this->db, $this->userId);

    $statementId = (int) $this->db->connection()->table('card_statements')->insertGetId([
        'user_id' => $this->userId,
        'account_id' => $accountId,
        'period_start' => '2026-05-01 00:00:00',
        'period_end' => '2026-05-31 00:00:00',
        'total_amount_minor' => -12_500,
        'open_balance_minor' => -12_500,
        'state' => 'open',
        'currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $creditId = (int) $this->db->connection()->table('card_statement_credits')->insertGetId([
        'user_id' => $this->userId,
        'from_statement_id' => $statementId,
        'amount_minor' => 500,
        'reason' => 'overpayment',
        'currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    deviceLocalChildReplay(
        $this->db,
        $this->deviceKeys,
        deviceLocalChildTombstone($this->signer, $this->sk, $this->userId, 'accounts', $accountId),
        $this->userId,
    );

    expect($this->db->connection()->table('accounts')->where('id', $accountId)->exists())->toBeFalse()
        ->and($this->db->connection()->table('card_statements')->where('id', $statementId)->exists())->toBeFalse()
        ->and($this->db->connection()->table('card_statement_credits')->where('id', $creditId)->exists())->toBeFalse()
        ->and(deviceLocalChildBlockedHolds($this->db, $this->userId))->toBe(0);
});

// The half that must NOT move. A mutation carries merge rules, so its own
// tombstone is the only thing allowed to remove it — delete it here and the
// peer's history of the row is the only account of it left, which hands it
// straight back on the next replay.
it('leaves a child that travels for its own tombstone and records the disagreement', function (): void {
    $scenarioId = deviceLocalChildScenario($this->db, $this->userId);

    $mutationId = (int) $this->db->connection()->table('forecast_scenario_mutations')->insertGetId([
        'user_id' => $this->userId,
        'forecast_scenario_id' => $scenarioId,
        'kind' => 'add_one_off',
        'payload' => '{"amount_minor":-2500}',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    deviceLocalChildReplay(
        $this->db,
        $this->deviceKeys,
        deviceLocalChildTombstone($this->signer, $this->sk, $this->userId, 'forecast_scenarios', $scenarioId),
        $this->userId,
    );

    expect($this->db->connection()->table('forecast_scenario_mutations')->where('id', $mutationId)->exists())->toBeTrue()
        ->and($this->db->connection()->table('forecast_scenarios')->where('id', $scenarioId)->exists())->toBeTrue()
        ->and(deviceLocalChildBlockedHolds($this->db, $this->userId))->toBe(1);
});

it('counts a blocked delete among the verdicts a later state can undo', function (): void {
    expect(QuarantineReason::recoverable())->toContain(QuarantineReason::DeleteBlockedByReference->value)
        ->and(QuarantineReason::keyRecoverable())->not->toContain(QuarantineReason::DeleteBlockedByReference->value);
});

// A hold nothing retires is a hold forever. The blocker going away is the
// answer this one was waiting for, and the row it names being gone is how the
// sweep can tell it has had it.
it('retires a blocked delete once the row it names is gone', function (): void {
    $scenarioId = deviceLocalChildScenario($this->db, $this->userId);

    $this->db->connection()->table('op_log_quarantine')->insert([
        'user_id' => $this->userId,
        'table_name' => 'forecast_scenarios',
        'pk' => (string) $scenarioId,
        'device_id' => 'desktop-device',
        'reason' => QuarantineReason::DeleteBlockedByReference->value,
        'hlc_l' => 1_800_000_000_000,
        'hlc_c' => 0,
        'raw_value' => null,
        'gdk_epoch' => null,
        'created_at' => '2026-06-14 09:00:00',
    ]);

    $this->actingAs($this->user);
    $reprojector = app(HistoryReprojector::class);

    $reprojector->replayQuarantined($this->userId, app(Session::class), null, null);
    expect(deviceLocalChildBlockedHolds($this->db, $this->userId))->toBe(1);

    $this->db->connection()->table('forecast_scenarios')->where('id', $scenarioId)->delete();
    $reprojector->replayQuarantined($this->userId, app(Session::class), null, null);

    expect(deviceLocalChildBlockedHolds($this->db, $this->userId))->toBe(0);
});
