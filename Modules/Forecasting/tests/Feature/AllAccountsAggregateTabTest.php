<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

function aatUser(): User
{
    return User::query()->create([
        'username' => 'aat-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function aatAccount(DatabaseManager $db, int $userId, string $name, ?int $buffer = null): int
{
    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'aat-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'AAT'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 100000,
        'opening_balance_as_of_date' => '2026-05-01',
        'forecast_min_buffer_minor' => $buffer,
    ]);
}

// One row per (user, horizon) carrying every account: ForecastQuery reads only
// the latest row for the tuple, so per-account inserts would shadow each other.
/**
 * @param  array<int, int>  $pointMinorByAccount  accountId => point estimate (minor)
 */
function aatForecastRun(DatabaseManager $db, int $userId, array $pointMinorByAccount, int $horizon): void
{
    $accountsBlock = [];
    foreach ($pointMinorByAccount as $accountId => $pointMinor) {
        $points = [];
        for ($d = 0; $d <= $horizon; $d++) {
            $points[] = [
                'date' => CarbonImmutable::parse('2026-05-01')->addDays($d)->toDateString(),
                'low_minor' => $pointMinor - 1000,
                'point_minor' => $pointMinor,
                'high_minor' => $pointMinor + 1000,
                'currency' => 'EUR',
            ];
        }
        $accountsBlock[(string) $accountId] = [
            'account_id' => $accountId,
            'account_name' => 'aat',
            'default_currency' => 'EUR',
            'today_balance_minor' => $pointMinor,
            'anchor_source' => 'user_input_opening_balance',
            'points' => $points,
        ];
    }

    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $userId,
        'scenario_id' => null,
        'horizon_days' => $horizon,
        'status' => 'complete',
        'result_json' => json_encode([
            'as_of' => '2026-05-01',
            'horizon_days' => $horizon,
            'accounts' => $accountsBlock,
        ]),
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = aatUser();
});

it('renders the All accounts tab as the default landing tab with line chart variant', function (): void {
    $a = aatAccount($this->db, $this->user->id, 'Alpha');
    $b = aatAccount($this->db, $this->user->id, 'Beta');
    aatForecastRun($this->db, $this->user->id, [$a => 100000, $b => 50000], 30);

    $response = $this->actingAs($this->user)->get('/forecast');

    $content = (string) $response->getContent();

    expect($content)->toContain('aria-selected="true"');
    expect($content)->toContain('>All accounts<');
    expect($content)->toContain('data-testid="all-accounts-aggregate-chart"');
    expect($content)->toContain('data-chart-variant="line"');
});

it('does NOT render the rangeArea chart on the default All accounts landing', function (): void {
    $a = aatAccount($this->db, $this->user->id, 'Alpha');
    aatForecastRun($this->db, $this->user->id, [$a => 100000], 30);

    $response = $this->actingAs($this->user)->get('/forecast');

    $content = (string) $response->getContent();

    expect($content)->not->toContain('forecast-chart-baseline-'.$a);
});

it('sums per-account point estimates in EUR on the aggregate chart payload', function (): void {
    $a = aatAccount($this->db, $this->user->id, 'Alpha');
    $b = aatAccount($this->db, $this->user->id, 'Beta');
    aatForecastRun($this->db, $this->user->id, [$a => 100000, $b => 50000], 30);

    $response = $this->actingAs($this->user)->get('/forecast');

    $content = (string) $response->getContent();

    // 100000 + 50000 minor, rendered as 1500 in major units.
    $matches = [];
    expect(preg_match('/data-options="([^"]*)"\s*>\s*<div\b[^>]*?data-testid="all-accounts-aggregate-chart"/s', $content, $matches))->toBe(1);
    $decoded = json_decode(html_entity_decode($matches[1], ENT_QUOTES), associative: true);
    expect($decoded)->toBeArray();
    expect($decoded['series'][0]['data'][0]['y'])->toBe(1500);
});

it('renders the buffer floor as the sum of per-account forecast_min_buffer_minor', function (): void {
    $a = aatAccount($this->db, $this->user->id, 'Alpha', buffer: 50000);
    $b = aatAccount($this->db, $this->user->id, 'Beta', buffer: 30000);
    aatForecastRun($this->db, $this->user->id, [$a => 100000, $b => 50000], 30);

    $response = $this->actingAs($this->user)->get('/forecast');

    $content = (string) $response->getContent();

    // The y2 annotation is the combined buffer floor: (50000 + 30000) / 100.
    $matches = [];
    expect(preg_match('/data-options="([^"]*)"\s*>\s*<div\b[^>]*?data-testid="all-accounts-aggregate-chart"/s', $content, $matches))->toBe(1);
    $decoded = json_decode(html_entity_decode($matches[1], ENT_QUOTES), associative: true);
    expect($decoded)->toBeArray();
    expect($decoded['annotations']['yaxis'][0]['y2'])->toBe(800);
    expect($decoded['annotations']['yaxis'][0]['fillColor'])->toBe('#FECDD3');
});

it('renders the per-account rangeArea chart when the URL pin selects an account', function (): void {
    $a = aatAccount($this->db, $this->user->id, 'Alpha');
    aatForecastRun($this->db, $this->user->id, [$a => 100000], 30);

    $response = $this->actingAs($this->user)->get('/forecast?account='.$a);

    $content = (string) $response->getContent();

    expect($content)->toContain('forecast-chart-baseline-'.$a);
    expect($content)->toMatch('/data-options="[^"]*rangeArea/');
    expect($content)->not->toContain('data-testid="all-accounts-aggregate-chart"');
});
