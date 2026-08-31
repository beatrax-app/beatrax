<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Calendar\Internal\Services\DailyBalanceAggregator;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;

// The bundled snapshot carries no peso, so a reader whose only balance account
// is an Argentinian one has a line the rate table cannot reach. Every corner
// of the grid printed the converted total anyway — a flat EUR0 on all 42 of
// them, with nothing saying the figure was censored, and the rose risk tint
// read off that same zero, so an account overdrawn only in pesos never showed.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'aub-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

const AUB_UNPRICED = 'ARS';

function aubAccount(DatabaseManager $db, int $userId, string $currency, int $baselineMinor): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'aub '.$currency,
        'slug' => 'aub-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00AUB'.strtoupper($hex),
        'default_currency' => $currency,
        'opening_balance_minor' => $baselineMinor,
        'opening_balance_as_of_date' => '2026-06-01',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function aubForecastRun(DatabaseManager $db, int $userId, int $accountId, string $currency, int $pointMinor): void
{
    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $userId,
        'scenario_id' => null,
        'horizon_days' => 365,
        'status' => 'complete',
        'result_json' => json_encode([
            'as_of' => '2026-06-12',
            'accounts' => [
                (string) $accountId => [
                    'account_id' => $accountId,
                    'account_name' => 'aub '.$currency,
                    'default_currency' => $currency,
                    'today_balance_minor' => $pointMinor,
                    'points' => [[
                        'date' => '2026-06-20',
                        'low_minor' => $pointMinor,
                        'point_minor' => $pointMinor,
                        'high_minor' => $pointMinor,
                        'currency' => $currency,
                    ]],
                ],
            ],
        ]),
        'created_at' => '2026-06-12 00:00:00',
        'updated_at' => '2026-06-12 00:00:00',
    ]);
}

it('names the currency the day could not be priced in instead of reporting the day at zero', function (): void {
    $account = aubAccount($this->db, $this->user->id, AUB_UNPRICED, 1_000_000);

    $result = app(DailyBalanceAggregator::class)->buildBalanceMap(
        [$account],
        $this->user,
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    );

    expect($result['map']['2026-06-05']->unconvertedCurrencies)->toBe([AUB_UNPRICED]);
});

it('tints a day rose when the only balance it has is overdrawn in a currency it cannot price', function (): void {
    $account = aubAccount($this->db, $this->user->id, AUB_UNPRICED, -1_000_000);
    aubForecastRun($this->db, $this->user->id, $account, AUB_UNPRICED, -1_000_000);

    $days = app(CalendarQuery::class)->forMonth($this->user, 2026, 6, null, [$account]);

    $june5 = array_values(array_filter($days, fn ($d): bool => $d->date->toDateString() === '2026-06-05'));

    expect($june5)->not->toBeEmpty()
        ->and($june5[0]->isRisk)->toBeTrue()
        ->and($june5[0]->unconvertedCurrencies)->toBe([AUB_UNPRICED]);
});

it('draws no figure on a cell whose balance it could not convert, and says why once', function (): void {
    $account = aubAccount($this->db, $this->user->id, AUB_UNPRICED, 1_000_000);
    aubForecastRun($this->db, $this->user->id, $account, AUB_UNPRICED, 1_000_000);

    $html = Livewire::actingAs($this->user)
        ->test(CalendarPage::class, [
            'month' => 6,
            'year' => 2026,
            'balanceAccountIds' => [$account],
        ])
        ->html();

    expect($html)->toContain(AUB_UNPRICED.' not converted')
        ->and($html)->not->toContain('€0');
});

it('leaves the start-of-day unknown rather than chaining a censored figure into it', function (): void {
    $account = aubAccount($this->db, $this->user->id, AUB_UNPRICED, 1_000_000);
    aubForecastRun($this->db, $this->user->id, $account, AUB_UNPRICED, 1_000_000);

    $days = app(CalendarQuery::class)->forMonth($this->user, 2026, 6, null, [$account]);

    foreach ($days as $day) {
        expect($day->sodBalanceMinor)->toBeNull($day->date->toDateString());
    }
});

// The other half of the rule: an unpriced account must not take the priced
// ones down with it. A reader holding euros and pesos keeps the euro line and
// is told the pesos are missing from it — which is what every other money
// surface in the app does with a currency it cannot reach.
it('keeps the part of the line it could price and names only what it could not', function (): void {
    $euro = aubAccount($this->db, $this->user->id, Currency::Eur->value, 300_000);
    $peso = aubAccount($this->db, $this->user->id, AUB_UNPRICED, 1_000_000);

    $days = app(CalendarQuery::class)->forMonth($this->user, 2026, 6, null, [$euro, $peso]);

    $june5 = array_values(array_filter($days, fn ($d): bool => $d->date->toDateString() === '2026-06-05'))[0];

    expect($june5->showsBalance())->toBeTrue()
        ->and($june5->eodBalanceMinor)->toBe(300_000)
        ->and($june5->unconvertedCurrencies)->toBe([AUB_UNPRICED]);

    $html = Livewire::actingAs($this->user)
        ->test(CalendarPage::class, [
            'month' => 6,
            'year' => 2026,
            'balanceAccountIds' => [$euro, $peso],
        ])
        ->html();

    expect($html)->toContain(AUB_UNPRICED.' not converted')
        ->and($html)->toContain('3,000');
});
