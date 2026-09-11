<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\DependentRowCascade;

uses(RefreshDatabase::class);

// `categorization_rules`, `rule_conditions` and `rule_actions` are device-local
// by product decision: each device authors its own. They carry merge rules only
// so an older peer's op still applies rather than quarantining.
function dlcUser(): User
{
    return User::query()->create([
        'username' => 'dlc-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function dlcRule(int $userId): int
{
    $ruleId = (int) DB::table('categorization_rules')->insertGetId([
        'user_id' => $userId,
        'priority' => 10,
        'combinator' => 'all',
        'active' => 1,
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    DB::table('rule_conditions')->insert([
        'rule_id' => $ruleId,
        'field' => 'description',
        'op' => 'contains',
        'value_type' => 'string',
        'value' => 'dlc',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    return $ruleId;
}

// The pk of a device-local row is a local autoincrement with no cross-device
// counterpart, so a tombstone naming one lands on whatever the peer happens to
// hold at that id -- an unrelated rule's condition, or nothing.
it('announces no tombstone for a child of a device-local table', function (): void {
    $user = dlcUser();
    $ruleId = dlcRule((int) $user->id);

    /** @var DependentRowCascade $cascade */
    $cascade = app(DependentRowCascade::class);
    $events = $cascade->delete('categorization_rules', $ruleId, (int) $user->id);

    expect($events)->toBe([])
        ->and(DB::table('rule_conditions')->where('rule_id', $ruleId)->count())->toBe(0);
});

// The positive control. A child of a table that DOES travel is still
// tombstoned, so the guard above cannot be silencing every cascade.
it('still announces a tombstone for a child that travels', function (): void {
    $user = dlcUser();

    $scenarioId = (int) DB::table('forecast_scenarios')->insertGetId([
        'user_id' => $user->id,
        'name' => 'dlc scenario',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    DB::table('forecast_scenario_mutations')->insert([
        'user_id' => $user->id,
        'forecast_scenario_id' => $scenarioId,
        'kind' => 'cancel_series',
        'target_series_id' => null,
        'payload' => '{"seriesId":1}',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    /** @var DependentRowCascade $cascade */
    $cascade = app(DependentRowCascade::class);
    $events = $cascade->delete('forecast_scenarios', $scenarioId, (int) $user->id);

    expect($events)->not->toBeEmpty()
        ->and($events[0]->table)->toBe('forecast_scenario_mutations')
        ->and($events[0]->mutationType)->toBe('delete');
});
