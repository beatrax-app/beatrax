<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Models\ForecastRun;
use Modules\Forecasting\Models\ForecastScenario;

uses(RefreshDatabase::class);

function sbsUser(string $username = 'sbs-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function sbsAccount(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN',
        'slug' => 'sbs-asn-'.$userId.'-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00SBS'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 100000,
        'opening_balance_as_of_date' => '2026-05-01',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function sbsForecastRun(DatabaseManager $db, int $userId, int $accountId, ?int $scenarioId, int $horizonDays, string $status, array $points): void
{
    $resultJson = [
        'as_of' => '2026-05-19',
        'horizon_days' => $horizonDays,
        'accounts' => [
            (string) $accountId => [
                'account_id' => $accountId,
                'account_name' => 'ASN',
                'default_currency' => 'EUR',
                'today_balance_minor' => 100000,
                'anchor_source' => 'user_input_opening_balance',
                'points' => $points,
            ],
        ],
    ];
    $run = new ForecastRun;
    $run->user_id = $userId;
    $run->scenario_id = $scenarioId;
    $run->horizon_days = $horizonDays;
    $run->status = $status;
    $run->save();
    $db->connection()->table('forecast_runs')->where('id', $run->id)->update([
        'result_json' => json_encode($resultJson),
    ]);
}

function sbsPoints(int $base, int $count, int $stepPerDay = 0): array
{
    $rows = [];
    for ($d = 0; $d <= $count; $d++) {
        $val = $base + ($d * $stepPerDay);
        $rows[] = [
            'date' => (new CarbonImmutable('2026-05-19'))->addDays($d)->toDateString(),
            'low_minor' => $val - 100,
            'point_minor' => $val,
            'high_minor' => $val + 100,
            'currency' => 'EUR',
        ];
    }

    return $rows;
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = sbsUser();
    $this->accountId = sbsAccount($db, $this->user->id);
});

it('renders only the baseline chart when scenarioId is null on a per-account tab', function (): void {
    sbsForecastRun($this->db, $this->user->id, $this->accountId, null, 30, 'complete', sbsPoints(100000, 30));
    $this->actingAs($this->user)
        ->get('/forecast?account='.$this->accountId)
        ->assertOk()
        ->assertSee('forecast-chart-baseline-')
        ->assertDontSee('forecast-chart-scenario-');
});

it('renders both panels when scenarioId is set', function (): void {
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Test scenario',
    ]);
    sbsForecastRun($this->db, $this->user->id, $this->accountId, null, 30, 'complete', sbsPoints(100000, 30));
    sbsForecastRun($this->db, $this->user->id, $this->accountId, $scenario->id, 30, 'complete', sbsPoints(110000, 30));
    $this->actingAs($this->user)
        ->get('/forecast?account='.$this->accountId.'&scenarioId='.$scenario->id)
        ->assertOk()
        ->assertSee('forecast-chart-baseline-')
        ->assertSee('forecast-chart-scenario-')
        ->assertSee('Net diff');
});

it('renders the Net diff tile with three horizon labels when a scenario is active', function (): void {
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Test scenario 2',
    ]);
    sbsForecastRun($this->db, $this->user->id, $this->accountId, null, 30, 'complete', sbsPoints(100000, 30));
    sbsForecastRun($this->db, $this->user->id, $this->accountId, null, 60, 'complete', sbsPoints(100000, 60));
    sbsForecastRun($this->db, $this->user->id, $this->accountId, null, 90, 'complete', sbsPoints(100000, 90));
    sbsForecastRun($this->db, $this->user->id, $this->accountId, $scenario->id, 30, 'complete', sbsPoints(105000, 30));
    $this->actingAs($this->user)
        ->get('/forecast?account='.$this->accountId.'&scenarioId='.$scenario->id)
        ->assertOk()
        ->assertSee('at day 30')
        ->assertSee('at day 60')
        ->assertSee('at day 90');
});

it('tints the Net diff numeric emerald-700 when the scenario improves the baseline', function (): void {
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Improver',
    ]);
    sbsForecastRun($this->db, $this->user->id, $this->accountId, null, 30, 'complete', sbsPoints(50000, 30));
    sbsForecastRun($this->db, $this->user->id, $this->accountId, $scenario->id, 30, 'complete', sbsPoints(70000, 30));
    $this->actingAs($this->user)
        ->get('/forecast?account='.$this->accountId.'&scenarioId='.$scenario->id)
        ->assertOk()
        ->assertSee('text-emerald-700');
});

it('tints the Net diff numeric rose-700 when the scenario worsens the baseline', function (): void {
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Worsener',
    ]);
    sbsForecastRun($this->db, $this->user->id, $this->accountId, null, 30, 'complete', sbsPoints(100000, 30));
    sbsForecastRun($this->db, $this->user->id, $this->accountId, $scenario->id, 30, 'complete', sbsPoints(80000, 30));
    $this->actingAs($this->user)
        ->get('/forecast?account='.$this->accountId.'&scenarioId='.$scenario->id)
        ->assertOk()
        ->assertSee('text-rose-700');
});

// A scenario that changes nothing is not an improvement, and tinting the
// panel emerald would tell the user it was one. Zero has to land on the same
// neutral ink the baseline panel uses.
it('leaves the scenario panel neutral when the scenario changes nothing by day 30', function (): void {
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'No-op',
    ]);
    sbsForecastRun($this->db, $this->user->id, $this->accountId, null, 30, 'complete', sbsPoints(100000, 30));
    sbsForecastRun($this->db, $this->user->id, $this->accountId, $scenario->id, 30, 'complete', sbsPoints(100000, 30));

    $this->actingAs($this->user)
        ->get('/forecast?account='.$this->accountId.'&scenarioId='.$scenario->id)
        ->assertOk()
        ->assertSee('Net diff')
        ->assertDontSee('#047857')
        ->assertDontSee('#BE123C');
});

it('activates the wire:poll.2s element only while the latest run is pending or running', function (): void {
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Computing',
    ]);
    sbsForecastRun($this->db, $this->user->id, $this->accountId, null, 30, 'complete', sbsPoints(100000, 30));
    sbsForecastRun($this->db, $this->user->id, $this->accountId, $scenario->id, 30, 'pending', sbsPoints(100000, 30));
    $this->actingAs($this->user)
        ->get('/forecast?account='.$this->accountId.'&scenarioId='.$scenario->id)
        ->assertOk()
        ->assertSee('wire:poll.2s');
});

it('does NOT render the wire:poll.2s element when both runs are complete', function (): void {
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Ready',
    ]);
    sbsForecastRun($this->db, $this->user->id, $this->accountId, null, 30, 'complete', sbsPoints(100000, 30));
    sbsForecastRun($this->db, $this->user->id, $this->accountId, $scenario->id, 30, 'complete', sbsPoints(105000, 30));
    $this->actingAs($this->user)
        ->get('/forecast?account='.$this->accountId.'&scenarioId='.$scenario->id)
        ->assertOk()
        ->assertDontSee('wire:poll.2s');
});

it('returns 404 when scenarioId belongs to another user', function (): void {
    $other = sbsUser('other');
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $other->id,
        'name' => 'Other user scenario',
    ]);
    sbsForecastRun($this->db, $this->user->id, $this->accountId, null, 30, 'complete', sbsPoints(100000, 30));
    $this->actingAs($this->user)
        ->get('/forecast?account='.$this->accountId.'&scenarioId='.$scenario->id)
        ->assertNotFound();
});

it('computes a shared y-axis range across both panels', function (): void {
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Shared y',
    ]);
    sbsForecastRun($this->db, $this->user->id, $this->accountId, null, 30, 'complete', sbsPoints(100000, 30));
    sbsForecastRun($this->db, $this->user->id, $this->accountId, $scenario->id, 30, 'complete', sbsPoints(50000, 30));
    $resp = $this->actingAs($this->user)->get('/forecast?account='.$this->accountId.'&scenarioId='.$scenario->id);
    $resp->assertOk();
    $html = $resp->getContent();
    preg_match_all('/&quot;yaxis&quot;:\{&quot;min&quot;:([\-0-9.]+),&quot;max&quot;:([\-0-9.]+)/', (string) $html, $matches);
    expect(count($matches[1] ?? []))->toBeGreaterThanOrEqual(2);
    expect($matches[1][0])->toBe($matches[1][1]);
    expect($matches[2][0])->toBe($matches[2][1]);
});
