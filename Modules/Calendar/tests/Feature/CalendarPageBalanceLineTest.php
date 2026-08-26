<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Core\Models\User;

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

// ForecastQuery reads a single forecast_runs row per (user_id, horizon_days,
// scenario_id), so repeat calls for one user have to merge into that row's
// accounts block rather than insert a second run.
function cpblForecastRun(DatabaseManager $db, int $userId, int $accountId, string $date, int $pointMinor, string $currency = 'EUR'): void
{
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
        $decoded = json_decode($existing->result_json, associative: true);
        if (! is_array($decoded)) {
            $decoded = ['as_of' => '2026-06-12', 'accounts' => []];
        }
        $decoded['accounts'][(string) $accountId] = $accountBlock;

        $db->connection()->table('forecast_runs')
            ->where('id', $existing->id)
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

    // 50000 + 25000 = 75000 minor, i.e. the €750.00 asserted below.
    cpblForecastRun($db, $user->id, $account1, '2026-06-20', 50000);
    cpblForecastRun($db, $user->id, $account2, '2026-06-20', 25000);

    Livewire::actingAs($user)
        ->test(CalendarPage::class, [
            'month' => 6,
            'year' => 2026,
            'balanceAccountIds' => [$account1, $account2],
        ])
        ->assertSee('750');
});

// The aria-label is the only place a phone announces the balance at all: the
// visible corner is desktop-only. All twenty-six locales wrote a currency sign
// in front of :amount and the blade passes Money, which writes its own, so a
// screen reader heard the sign twice — and heard EUR on a dollar account.
it('announces the day balance with one currency symbol', function (): void {
    $db = app(DatabaseManager::class);
    $user = cpblUser('cpbl-aria');
    $account = cpblAccount($db, $user->id, 'ASN Checking');
    cpblForecastRun($db, $user->id, $account, '2026-06-20', 75000);

    $html = Livewire::actingAs($user)
        ->test(CalendarPage::class, [
            'month' => 6,
            'year' => 2026,
            'balanceAccountIds' => [$account],
        ])
        ->html();

    expect($html)->not->toContain('€€')
        ->and($html)->toContain('projected balance €750');
});

it('FX-converts a USD account\'s forecast points to the base currency instead of adding raw minor units', function (): void {
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

    cpblForecastRun($db, $user->id, $eurAccount, '2026-06-20', 123400, 'EUR');
    cpblForecastRun($db, $user->id, $usdAccount, '2026-06-20', 200000, 'USD');

    // Converted: 123400 + (200000 / 2) = 223400 minor. The group mark is the
    // reader's, and this suite reads in English, so the cell says "€2,234".
    // A raw cross-currency sum would be 323400 minor → "€3.234".
    Livewire::actingAs($user)
        ->test(CalendarPage::class, [
            'month' => 6,
            'year' => 2026,
            'balanceAccountIds' => [$eurAccount, $usdAccount],
        ])
        ->assertSee('2,234')
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
        ->assertDontSee('9999');
});
