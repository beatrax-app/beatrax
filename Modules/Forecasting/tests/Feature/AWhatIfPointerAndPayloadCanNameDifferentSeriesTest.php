<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Enums\ScenarioTemplate;
use Modules\Forecasting\Public\Actions\CreateScenarioFromTemplate;

function retargetedWhatIfUser(): User
{
    return User::query()->create([
        'username' => 'retgt-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function retargetedWhatIfSeries(DatabaseManager $db, int $userId, string $name): int
{
    return $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -999,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'retgt::'.bin2hex(random_bytes(4)),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

function retargetedWhatIfPayloadSeries(DatabaseManager $db, int $scenarioId): ?int
{
    $payload = $db->connection()->table('forecast_scenario_mutations')
        ->where('forecast_scenario_id', $scenarioId)
        ->value('payload');

    $decoded = is_string($payload) ? json_decode($payload, true) : null;

    return is_array($decoded) && is_numeric($decoded['seriesId'] ?? null) ? (int) $decoded['seriesId'] : null;
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = retargetedWhatIfUser();
    /** @var CreateScenarioFromTemplate $action */
    $action = $this->app->make(CreateScenarioFromTemplate::class);
    $this->create = $action;
});

// `target_series_id` is a denormalisation of `payload`, and the two are
// separate columns of a row that merges per field. A retarget made on two
// devices at once lands the pointer from one and the payload from the other,
// which is what the raw UPDATE below stands in for.
it('does not hand back a what-if whose payload cancels a different series', function (): void {
    $netflix = retargetedWhatIfSeries($this->db, (int) $this->user->id, 'Netflix');
    $spotify = retargetedWhatIfSeries($this->db, (int) $this->user->id, 'Spotify');

    $cancelsNetflix = ($this->create)(ScenarioTemplate::Cancel, $netflix, $this->user);

    $this->db->connection()->table('forecast_scenario_mutations')
        ->where('forecast_scenario_id', $cancelsNetflix)
        ->update(['target_series_id' => $spotify]);

    $cancelsSpotify = ($this->create)(ScenarioTemplate::Cancel, $spotify, $this->user);

    expect($cancelsSpotify)->not->toBe($cancelsNetflix)
        ->and(retargetedWhatIfPayloadSeries($this->db, $cancelsSpotify))->toBe($spotify)
        ->and(retargetedWhatIfPayloadSeries($this->db, $cancelsNetflix))->toBe($netflix);
});

// A payload this build cannot read is one the lookup cannot claim is already
// there. A duplicate scenario is recoverable; handing back one that cancels
// another series is not.
it('skips a candidate whose payload it cannot read at all', function (): void {
    $netflix = retargetedWhatIfSeries($this->db, (int) $this->user->id, 'Netflix');

    $first = ($this->create)(ScenarioTemplate::Cancel, $netflix, $this->user);

    $this->db->connection()->table('forecast_scenario_mutations')
        ->where('forecast_scenario_id', $first)
        ->update(['payload' => 'not json at all']);

    // The name lookup is the fallback behind the series lookup, so the row has
    // to be renamed out of its way for the skip to be what is read here.
    $this->db->connection()->table('forecast_scenarios')
        ->where('id', $first)
        ->update(['name' => 'renamed-'.bin2hex(random_bytes(4))]);

    $second = ($this->create)(ScenarioTemplate::Cancel, $netflix, $this->user);

    expect($second)->not->toBe($first)
        ->and(retargetedWhatIfPayloadSeries($this->db, $second))->toBe($netflix);
});

// Readable JSON the payload class still refuses: the seriesId it requires is
// not there, so constructing it raises rather than returning a wrong series.
it('skips a candidate whose payload decodes but does not build', function (): void {
    $netflix = retargetedWhatIfSeries($this->db, (int) $this->user->id, 'Netflix');

    $first = ($this->create)(ScenarioTemplate::Cancel, $netflix, $this->user);

    $this->db->connection()->table('forecast_scenario_mutations')
        ->where('forecast_scenario_id', $first)
        ->update(['payload' => '{"notASeriesId":1}']);

    // The name lookup is the fallback behind the series lookup, so the row has
    // to be renamed out of its way for the skip to be what is read here.
    $this->db->connection()->table('forecast_scenarios')
        ->where('id', $first)
        ->update(['name' => 'renamed-'.bin2hex(random_bytes(4))]);

    $second = ($this->create)(ScenarioTemplate::Cancel, $netflix, $this->user);

    expect($second)->not->toBe($first)
        ->and(retargetedWhatIfPayloadSeries($this->db, $second))->toBe($netflix);
});

it('still hands back the one whose payload agrees with its pointer', function (): void {
    $netflix = retargetedWhatIfSeries($this->db, (int) $this->user->id, 'Netflix');

    $first = ($this->create)(ScenarioTemplate::Cancel, $netflix, $this->user);
    $second = ($this->create)(ScenarioTemplate::Cancel, $netflix, $this->user);

    expect($second)->toBe($first)
        ->and($this->db->connection()->table('forecast_scenarios')->where('user_id', $this->user->id)->count())->toBe(1);
});
