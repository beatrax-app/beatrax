<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Core\Models\User;

/*
 * CalendarPage — daily balance line sourced from ForecastQuery (CAL-02).
 *
 * RED state (Phase 6 Plan 01): CalendarPage does not yet call ForecastQuery;
 * these tests will fail until Plan 02/03 wire the balance line.
 *
 * Contract being tested:
 *   - With two balance-included accounts and seeded forecast_runs, the
 *     day-cell eodBalanceMinor equals the sum of each account's
 *     ForecastPointDto.pointMinor for that date.
 *   - The balance is rendered in the cell in a recognisable format
 *     (e.g. "€ 1.234,56" or the formatted equivalent).
 *   - Cross-user isolation: balance is derived only from the authenticated
 *     user's accounts; no foreign account data leaks.
 */

function cpblUser(string $suffix = 'cpbl'): User
{
    return User::query()->create([
        'username' => $suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function cpblAccount(DatabaseManager $db, int $userId, string $name): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'cpbl-'.$hex,
        'kind' => 'asn',
        'iban' => 'NL00CPBL'.strtoupper($hex),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 100000,
        'opening_balance_as_of_date' => '2026-06-01',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function cpblForecastRun(DatabaseManager $db, int $userId, int $accountId, string $date, int $pointMinor): void
{
    // Seed a completed forecast_run with a single-day result_json so the
    // ForecastQuery can return a ForecastDto for the test assertion.
    $resultJson = json_encode([
        'points' => [
            ['date' => $date, 'lowMinor' => $pointMinor - 100, 'pointMinor' => $pointMinor, 'highMinor' => $pointMinor + 100, 'currency' => 'EUR'],
        ],
        'todayBalanceMinor' => $pointMinor,
        'seriesConfidence' => [],
    ]);

    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'scenario_id' => null,
        'horizon_days' => 365,
        'status' => 'complete',
        'result_json' => $resultJson,
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

it('renders the day-end balance from summed forecast points for balance-included accounts', function (): void {
    $db = app(DatabaseManager::class);
    $user = cpblUser('cpbl-balance');
    $account1 = cpblAccount($db, $user->id, 'ASN Checking');
    $account2 = cpblAccount($db, $user->id, 'PayPal');

    // Seed forecast for 2026-06-20: 50000 + 25000 = 75000 minor (€750.00)
    cpblForecastRun($db, $user->id, $account1, '2026-06-20', 50000);
    cpblForecastRun($db, $user->id, $account2, '2026-06-20', 25000);

    Livewire::actingAs($user)
        ->test(CalendarPage::class, [
            'month' => 6,
            'year' => 2026,
            'balanceAccountIds' => [$account1, $account2],
        ])
        ->assertSee('750');  // The summed balance €750.00 appears in the cell
});

it('does not render balance data from another user\'s accounts', function (): void {
    $db = app(DatabaseManager::class);
    $owner = cpblUser('cpbl-owner');
    $other = cpblUser('cpbl-other');

    $otherAccount = cpblAccount($db, $other->id, 'Other Account');
    cpblForecastRun($db, $other->id, $otherAccount, '2026-06-20', 999999);

    Livewire::actingAs($owner)
        ->test(CalendarPage::class, [
            'month' => 6,
            'year' => 2026,
            'balanceAccountIds' => [$otherAccount],  // foreign account id
        ])
        // The foreign account balance must not be rendered;
        // the page either throws NotFoundHttpException or shows no balance
        ->assertDontSee('9999');
});
