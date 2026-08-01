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

function cpblAccount(DatabaseManager $db, int $userId, string $name, string $currency = 'EUR'): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'cpbl-'.$hex,
        'kind' => 'bank',
        'iban' => 'NL00CPBL'.strtoupper($hex),
        'default_currency' => $currency,
        'opening_balance_minor' => 100000,
        'opening_balance_as_of_date' => '2026-06-01',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

/**
 * Seed or update a forecast_run for the given user with one account's data.
 *
 * ForecastQuery reads a SINGLE forecast_runs row per (user_id, horizon_days,
 * scenario_id) tuple, then looks up accounts[$accountId] inside result_json.
 * Multiple calls for the same user upsert into the same row's accounts block.
 */
function cpblForecastRun(DatabaseManager $db, int $userId, int $accountId, string $date, int $pointMinor, string $currency = 'EUR'): void
{
    // Check if a run already exists for this user+horizon combination
    $existing = $db->connection()->table('forecast_runs')
        ->where('user_id', $userId)
        ->where('horizon_days', 365)
        ->whereNull('scenario_id')
        ->first(['id', 'result_json']);

    $accountBlock = [
        'account_id' => $accountId,
        'account_name' => 'Test Account',
        'default_currency' => $currency,
        'today_balance_minor' => $pointMinor,
        'points' => [
            [
                'date' => $date,
                'low_minor' => $pointMinor - 100,
                'point_minor' => $pointMinor,
                'high_minor' => $pointMinor + 100,
                'currency' => $currency,
            ],
        ],
    ];

    if ($existing !== null) {
        // Merge the new account block into the existing result_json
        $decoded = json_decode($existing->result_json, associative: true); // @phpstan-ignore-line
        if (! is_array($decoded)) {
            $decoded = ['as_of' => '2026-06-12', 'accounts' => []];
        }
        $decoded['accounts'][(string) $accountId] = $accountBlock;

        $db->connection()->table('forecast_runs')
            ->where('id', $existing->id) // @phpstan-ignore-line
            ->update(['result_json' => json_encode($decoded), 'updated_at' => '2026-06-12 00:00:00']);
    } else {
        $resultJson = json_encode([
            'as_of' => '2026-06-12',
            'accounts' => [
                (string) $accountId => $accountBlock,
            ],
        ]);

        $db->connection()->table('forecast_runs')->insert([
            'user_id' => $userId,
            'scenario_id' => null,
            'horizon_days' => 365,
            'status' => 'complete',
            'result_json' => $resultJson,
            'created_at' => '2026-06-12 00:00:00',
            'updated_at' => '2026-06-12 00:00:00',
        ]);
    }
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

it('FX-converts a USD account\'s forecast points to the base currency instead of adding raw minor units (CR-02)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cpblUser('cpbl-fx');
    $eurAccount = cpblAccount($db, $user->id, 'ASN Checking', 'EUR');
    $usdAccount = cpblAccount($db, $user->id, 'Google Play USD', 'USD');

    // EUR→USD rate 2.0: USD 2 000.00 is worth EUR 1 000.00.
    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => 'EUR',
        'quote_currency' => 'USD',
        'rate_date' => '2026-06-12',
        'rate' => '2.00000000',
        'source' => 'test',
        'created_at' => '2026-06-12 00:00:00',
        'updated_at' => '2026-06-12 00:00:00',
    ]);

    // EUR account: €1 234.00 on June 20; USD account: $2 000.00 on June 20.
    cpblForecastRun($db, $user->id, $eurAccount, '2026-06-20', 123400, 'EUR');
    cpblForecastRun($db, $user->id, $usdAccount, '2026-06-20', 200000, 'USD');

    // Converted: 123400 + (200000 / 2) = 223400 minor → "€2.234" in the cell.
    // A raw cross-currency sum would be 323400 minor → "€3.234".
    Livewire::actingAs($user)
        ->test(CalendarPage::class, [
            'month' => 6,
            'year' => 2026,
            'balanceAccountIds' => [$eurAccount, $usdAccount],
        ])
        ->assertSee('2.234')
        ->assertDontSee('3.234');
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
