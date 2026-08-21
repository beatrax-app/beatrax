<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;

function cpcsUser(string $suffix = 'cpcs'): User
{
    return User::query()->create([
        'username' => $suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function cpcsAccount(DatabaseManager $db, int $userId, string $name): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'cpcs-'.$hex,
        'kind' => 'bank',
        'iban' => 'NL00CPCS'.strtoupper($hex),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 100000,
        'opening_balance_as_of_date' => '2026-06-01',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function cpcsSeries(User $user, string $name): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -2000,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'cpcs::'.$name,
        'next_expected_at' => CarbonImmutable::parse('2026-06-20'),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('renders "—" in the balance corner when ForecastDto.isComputing is true for a balance account', function (): void {
    $db = app(DatabaseManager::class);
    $user = cpcsUser('cpcs-computing');
    $accountId = cpcsAccount($db, $user->id, 'Computing Account');

    // A run genuinely in flight. A missing run is not the same thing and no
    // longer reports as computing, or the calendar would promise a projection
    // nothing is going to deliver.
    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $user->id,
        'scenario_id' => null,
        'horizon_days' => 365,
        'status' => 'running',
        'result_json' => null,
        'created_at' => '2026-06-12 00:00:00',
        'updated_at' => '2026-06-12 00:00:00',
    ]);

    Livewire::actingAs($user)
        ->test(CalendarPage::class, [
            'month' => 6,
            'year' => 2026,
            'balanceAccountIds' => [$accountId],
        ])
        ->assertSee('—');
});

it('still renders series entries when the forecast is in computing state', function (): void {
    $db = app(DatabaseManager::class);
    $user = cpcsUser('cpcs-entries-while-computing');
    $accountId = cpcsAccount($db, $user->id, 'Computing Account 2');

    cpcsSeries($user, 'Spotify Computing');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, [
            'month' => 6,
            'year' => 2026,
            'balanceAccountIds' => [$accountId],
        ])
        ->assertSee('Spotify Computing');
});
