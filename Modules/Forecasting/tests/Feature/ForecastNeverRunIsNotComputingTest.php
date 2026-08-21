<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Services\ForecastQuery;

uses(RefreshDatabase::class);

// A missing, failed and unreadable run all resolved to the same "still
// computing" sentinel, so a phone that had never computed one sat on
// "Prognose wordt bijgewerkt…" forever with an empty jobs table behind it.

function fnrUser(): User
{
    return User::query()->create([
        'username' => 'fnr-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function fnrAccount(DatabaseManager $db, int $userId): int
{
    $hex = bin2hex(random_bytes(4));

    return (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN',
        'slug' => 'fnr-'.$hex,
        'kind' => 'bank',
        'iban' => 'NL00FNR'.strtoupper($hex),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 100000,
        'opening_balance_as_of_date' => '2026-06-01',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function fnrRun(DatabaseManager $db, int $userId, string $status, ?string $json): void
{
    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $userId,
        'scenario_id' => null,
        'horizon_days' => 365,
        'status' => $status,
        'result_json' => $json,
        'created_at' => '2026-06-12 00:00:00',
        'updated_at' => '2026-06-12 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('does not claim to be computing when a forecast has never been run', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = fnrUser();
    $accountId = fnrAccount($db, (int) $user->id);

    $dto = app(ForecastQuery::class)->forUser($accountId, 365, null, $user);

    expect($dto->isComputing)->toBeFalse();
});

it('does not claim to be computing when the last run failed', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = fnrUser();
    $accountId = fnrAccount($db, (int) $user->id);
    fnrRun($db, (int) $user->id, 'failed', null);

    expect(app(ForecastQuery::class)->forUser($accountId, 365, null, $user)->isComputing)->toBeFalse();
});

it('does not claim to be computing when a completed run is unreadable', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = fnrUser();
    $accountId = fnrAccount($db, (int) $user->id);
    fnrRun($db, (int) $user->id, 'complete', 'not json at all');

    expect(app(ForecastQuery::class)->forUser($accountId, 365, null, $user)->isComputing)->toBeFalse();
});

it('does say it is computing while a run is actually in flight', function (string $status): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = fnrUser();
    $accountId = fnrAccount($db, (int) $user->id);
    fnrRun($db, (int) $user->id, $status, null);

    expect(app(ForecastQuery::class)->forUser($accountId, 365, null, $user)->isComputing)->toBeTrue();
})->with(['pending', 'running']);
