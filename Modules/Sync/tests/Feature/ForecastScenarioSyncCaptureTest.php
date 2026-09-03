<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Actions\AddScenarioMutation;
use Modules\Forecasting\Public\Actions\CreateScenario;
use Modules\Forecasting\Public\Actions\DeleteScenario;
use Modules\Forecasting\Public\Actions\EditScenarioMutation;
use Modules\Forecasting\Public\Actions\RemoveScenarioMutation;
use Modules\Forecasting\Public\Actions\RenameScenario;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Public\Services\DependentRowCascade;

uses(RefreshDatabase::class);

// forecast_scenarios is only the named box; what a what-if actually does lives
// in forecast_scenario_mutations, which was uncovered. Even once the container
// synced, the peer received the scenario as empty and projected the baseline.

function scenarioSyncUser(): User
{
    return User::query()->create([
        'username' => 'scenario-sync-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// Binds the device identity the capture listener resolves, and hands back the
// public key so the same history can be verified as a peer would verify it.
function bindScenarioSyncWriter(int $userId): string
{
    $keypair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keypair);

    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'scenario-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => $publicKey,
    ]));

    return bin2hex($publicKey);
}

/** @return Collection<int, stdClass> */
function scenarioOps(int $userId, string $table)
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', $table)
        ->get();
}

// Relative, and deliberately so. ScenarioHorizonBounds refuses a one-off dated
// before today, so the literal '2026-09-01' this carried was an expiry date: it
// passed until the day rolled over to the 2nd and then failed four tests at
// once, on a branch that had touched none of them.
function oneOffPayload(int $amountMinor = 12500): AddOneOffPayload
{
    return new AddOneOffPayload(
        date: CarbonImmutable::now()->addDays(30)->toDateString(),
        amountMinor: $amountMinor,
        currency: 'EUR',
        direction: 'expense',
        note: 'Nieuwe wasmachine',
    );
}

it('captures a scenario the moment it is created', function (): void {
    $user = scenarioSyncUser();
    bindScenarioSyncWriter((int) $user->id);

    app(CreateScenario::class)($user, 'Zonder Netflix', 'Wat als we opzeggen');

    $ops = scenarioOps((int) $user->id, 'forecast_scenarios');

    expect($ops)->not->toBeEmpty('a scenario that reaches no op log can never reach a peer')
        ->and($ops->pluck('op_type')->unique()->all())->toBe(['create_row'])
        ->and($ops->pluck('field')->all())->toContain('user_id', 'name', 'description', 'created_at');
});

it('captures a rename as a set on the name alone', function (): void {
    $user = scenarioSyncUser();
    $scenarioId = app(CreateScenario::class)($user, 'Zonder Netflix');

    // Bound after the create so only the rename's ops are in view.
    bindScenarioSyncWriter((int) $user->id);

    app(RenameScenario::class)($scenarioId, $user, 'Zonder streaming');

    $ops = scenarioOps((int) $user->id, 'forecast_scenarios');

    expect($ops->pluck('op_type')->unique()->all())->toBe(['set'])
        ->and($ops->pluck('field')->all())->toBe(['name'])
        ->and($ops->pluck('pk')->all())->toBe([(string) $scenarioId]);
});

it('captures deleting a scenario as a single tombstone', function (): void {
    $user = scenarioSyncUser();
    $scenarioId = app(CreateScenario::class)($user, 'Zonder Netflix');

    bindScenarioSyncWriter((int) $user->id);

    app(DeleteScenario::class)($scenarioId, $user);

    $ops = scenarioOps((int) $user->id, 'forecast_scenarios');

    expect($ops->pluck('op_type')->all())->toBe(['delete_tombstone'])
        ->and($ops->pluck('pk')->all())->toBe([(string) $scenarioId]);
});

it('captures a scenario mutation with the payload the database stores', function (): void {
    $user = scenarioSyncUser();
    $scenarioId = app(CreateScenario::class)($user, 'Zonder Netflix');

    bindScenarioSyncWriter((int) $user->id);

    app(AddScenarioMutation::class)(
        $scenarioId,
        $user,
        ScenarioMutationKind::AddOneOff->value,
        oneOffPayload(),
    );

    $ops = scenarioOps((int) $user->id, 'forecast_scenario_mutations');

    expect($ops)->not->toBeEmpty('without the mutation the peer receives an empty named scenario')
        ->and($ops->pluck('op_type')->unique()->all())->toBe(['create_row'])
        ->and($ops->pluck('field')->all())->toContain('user_id', 'forecast_scenario_id', 'kind', 'payload');

    $payloadOp = $ops->firstWhere('field', 'payload');

    expect((string) $payloadOp->value)->toContain('Nieuwe wasmachine');
});

it('captures an edited mutation as a set on the two columns an edit can move', function (): void {
    $user = scenarioSyncUser();
    $scenarioId = app(CreateScenario::class)($user, 'Zonder Netflix');
    $mutationId = app(AddScenarioMutation::class)(
        $scenarioId,
        $user,
        ScenarioMutationKind::AddOneOff->value,
        oneOffPayload(),
    );

    bindScenarioSyncWriter((int) $user->id);

    app(EditScenarioMutation::class)($mutationId, $user, oneOffPayload(19900));

    $ops = scenarioOps((int) $user->id, 'forecast_scenario_mutations');

    expect($ops->pluck('op_type')->unique()->all())->toBe(['set'])
        ->and($ops->pluck('field')->sort()->values()->all())->toBe(['payload', 'target_series_id']);
});

it('captures removing a mutation as a tombstone', function (): void {
    $user = scenarioSyncUser();
    $scenarioId = app(CreateScenario::class)($user, 'Zonder Netflix');
    $mutationId = app(AddScenarioMutation::class)(
        $scenarioId,
        $user,
        ScenarioMutationKind::AddOneOff->value,
        oneOffPayload(),
    );

    bindScenarioSyncWriter((int) $user->id);

    app(RemoveScenarioMutation::class)($mutationId, $user);

    $ops = scenarioOps((int) $user->id, 'forecast_scenario_mutations');

    expect($ops->pluck('op_type')->all())->toBe(['delete_tombstone'])
        ->and($ops->pluck('pk')->all())->toBe([(string) $mutationId]);
});

it('rebuilds the scenario and its contents on a peer that saw neither', function (): void {
    $user = scenarioSyncUser();
    $publicKeyHex = bindScenarioSyncWriter((int) $user->id);

    $scenarioId = app(CreateScenario::class)($user, 'Zonder Netflix', 'Wat als we opzeggen');
    $mutationId = app(AddScenarioMutation::class)(
        $scenarioId,
        $user,
        ScenarioMutationKind::AddOneOff->value,
        oneOffPayload(),
    );

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();

    // Stand in for the receiving device: the same signed history against a
    // database holding neither the container nor its contents.
    $scenarioIds = $connection->table('forecast_scenarios')->where('user_id', $user->id)
        ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    app(DependentRowCascade::class)->deleteAll('forecast_scenarios', $scenarioIds, $user->id);
    $connection->table('forecast_scenarios')->where('user_id', $user->id)->delete();

    $entries = [];
    foreach ($connection->table('op_log_entries')->where('user_id', $user->id)->orderBy('hlc_l')->orderBy('hlc_c')->get() as $row) {
        $entries[] = new OpLogEntry(
            table: (string) $row->table_name,
            pk: is_numeric($row->pk) ? (int) $row->pk : (string) $row->pk,
            field: (string) $row->field,
            value: $row->value === null ? null : (string) $row->value,
            hlcL: (int) $row->hlc_l,
            hlcC: (int) $row->hlc_c,
            deviceId: (string) $row->device_id,
            opType: OpType::from((string) $row->op_type),
            signature: (string) $row->signature,
            userId: (int) $user->id,
            gdkEpoch: $row->gdk_epoch === null ? null : (int) $row->gdk_epoch,
        );
    }

    (new OpLogReplayer(
        db: $db,
        deviceKeys: ['scenario-device' => $publicKeyHex],
        rules: new MergeRulesRegistry,
    ))->replay($entries, (int) $user->id);

    $scenario = $connection->table('forecast_scenarios')->where('id', $scenarioId)->first();
    $mutation = $connection->table('forecast_scenario_mutations')->where('id', $mutationId)->first();

    expect($scenario)->not->toBeNull('the peer has to end up holding the scenario itself')
        ->and((string) $scenario->name)->toBe('Zonder Netflix')
        ->and($mutation)->not->toBeNull('a scenario without its mutations projects the baseline and nothing else')
        ->and((int) $mutation->forecast_scenario_id)->toBe($scenarioId)
        ->and((string) $mutation->kind)->toBe('add_one_off')
        ->and((string) $mutation->payload)->toContain('Nieuwe wasmachine');
});
