<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Enums\ScenarioTemplate;
use Modules\Forecasting\Public\Actions\CreateScenarioFromTemplate;

function wtifUser(): User
{
    return User::query()->create([
        'username' => 'wtif-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function wtifSeries(DatabaseManager $db, int $userId, string $name): int
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
        'cluster_key' => 'wtif::'.bin2hex(random_bytes(4)),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = wtifUser();
});

afterEach(fn () => app()->setLocale('en'));

it('names the scenario in the reader language', function (): void {
    app()->setLocale('nl');
    $seriesId = wtifSeries($this->db, (int) $this->user->id, 'Netflix');

    /** @var CreateScenarioFromTemplate $action */
    $action = $this->app->make(CreateScenarioFromTemplate::class);
    $newId = ($action)(ScenarioTemplate::Cancel, $seriesId, $this->user);

    expect($this->db->connection()->table('forecast_scenarios')->where('id', $newId)->value('name'))
        ->toBe('Opzeggen Netflix');
});

it('finds the Dutch reader\'s own scenario again on a second click', function (): void {
    app()->setLocale('nl');
    $seriesId = wtifSeries($this->db, (int) $this->user->id, 'Netflix');

    /** @var CreateScenarioFromTemplate $action */
    $action = $this->app->make(CreateScenarioFromTemplate::class);
    $first = ($action)(ScenarioTemplate::Cancel, $seriesId, $this->user);
    $second = ($action)(ScenarioTemplate::Cancel, $seriesId, $this->user);

    expect($second)->toBe($first)
        ->and($this->db->connection()->table('forecast_scenarios')->where('user_id', $this->user->id)->count())->toBe(1);
});

it('finds it again even after the reader renames it, and after they switch language', function (): void {
    app()->setLocale('nl');
    $seriesId = wtifSeries($this->db, (int) $this->user->id, 'Netflix');

    /** @var CreateScenarioFromTemplate $action */
    $action = $this->app->make(CreateScenarioFromTemplate::class);
    $first = ($action)(ScenarioTemplate::Cancel, $seriesId, $this->user);

    $this->db->connection()->table('forecast_scenarios')->where('id', $first)->update(['name' => 'Mijn eigen naam']);
    app()->setLocale('en');

    expect(($action)(ScenarioTemplate::Cancel, $seriesId, $this->user))->toBe($first)
        ->and($this->db->connection()->table('forecast_scenarios')->where('user_id', $this->user->id)->count())->toBe(1);
});

it('keeps the two templates apart for the same series', function (): void {
    $seriesId = wtifSeries($this->db, (int) $this->user->id, 'Netflix');

    /** @var CreateScenarioFromTemplate $action */
    $action = $this->app->make(CreateScenarioFromTemplate::class);
    $cancel = ($action)(ScenarioTemplate::Cancel, $seriesId, $this->user);
    $reprice = ($action)(ScenarioTemplate::ChangeAmount, $seriesId, $this->user, 1499);

    expect($reprice)->not->toBe($cancel);
});
